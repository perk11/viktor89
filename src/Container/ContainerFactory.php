<?php

namespace Perk11\Viktor89\Container;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Perk11\Viktor89\PersonaAwareSystemPromptReader;
use Perk11\Viktor89\ProcessingResultExecutor;

/**
 * Builds, compiles and caches the Symfony DI container.
 *
 * The container is compiled once (dumped to a PHP file under data/generated/)
 * and loaded by every worker process. Configuration/parameter changes are
 * reflected by a parameter hash baked into the cache filename; source/config
 * file changes trigger a recompile on the next warmup().
 *
 * Classes that cannot be cleanly autowired as shared singletons (per-message
 * state, multiple instances with different config, closures, value objects,
 * exceptions, unbindable scalars, ...) are pruned before compilation and stay
 * manually wired in ProcessMessageTask.
 */
final class ContainerFactory
{
    private const COMPILED_CLASS = 'Viktor89CompiledContainer';
    private const DATABASE_NAME = 'siepatch-non-instruct5';

    /** Resolvable-but-unsafe-as-a-shared-singleton (kept manual in ProcessMessageTask). */
    private const SEMANTIC_EXCLUDE = [
        // needs a per-message beforeMessageSentNotifier closure
        ProcessingResultExecutor::class,
        // depends on UserPreferenceReaderInterface, which has several implementations; the
        // container cannot pick the right one, and it is built manually per message anyway
        PersonaAwareSystemPromptReader::class,
    ];

    /** Scalar constructor parameters that are bound globally from env/config. */
    private const BINDED_PARAM_NAMES = [
        'telegramBotId'    => true,
        'botUserId'        => true,
        'telegramBotUserId'=> true,
        'telegramBotUsername' => true,
        'telegramBotUserName'=> true,
        'botUserName'      => true,
        'telegramBotApiKey'=> true,
        'whisperCppUri'    => true,
        'name'             => true,
    ];

    private static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function cacheDir(): string
    {
        return self::projectDir() . '/data/generated';
    }

    private static function configPath(): string
    {
        return self::projectDir() . '/config.json';
    }

    private static function servicesPath(): string
    {
        return self::projectDir() . '/config/services.php';
    }

    private static function paramHash(int $botId, string $botUsername, string $botApiKey): string
    {
        return md5($botId . '|' . $botUsername . '|' . $botApiKey);
    }

    private static function cacheFile(int $botId, string $botUsername, string $botApiKey): string
    {
        return self::cacheDir() . '/' . self::COMPILED_CLASS . '_' . self::paramHash($botId, $botUsername, $botApiKey) . '.php';
    }

    /**
     * Called once at startup (main process) to ensure a compiled container is
     * dumped to disk for worker processes to load. Recompiles when the cached
     * container is missing or older than any source/config file.
     */
    public static function warmup(int $botId, string $botUsername, string $botApiKey): void
    {
        $file = self::cacheFile($botId, $botUsername, $botApiKey);
        if (file_exists($file) && filemtime($file) >= self::newestSourceMtime()) {
            return;
        }

        self::ensureCacheDir();
        $container = self::build($botId, $botUsername, $botApiKey);
        $container->compile();
        self::dump($container, $file);
    }

    /**
     * Returns a container instance for a worker. Loads the compiled container
     * dumped by warmup(); falls back to building (and compiling) one in memory
     * without writing to disk, so concurrent workers never race on the cache
     * file and the hot path never recompiles. Staleness is handled by warmup()
     * at startup (and by the parameter hash baked into the filename, which
     * invalidates the cache when the bot token/username change).
     */
    public static function getContainer(int $botId, string $botUsername, string $botApiKey): ContainerInterface
    {
        $file = self::cacheFile($botId, $botUsername, $botApiKey);
        if (file_exists($file)) {
            try {
                return self::loadCompiled($file);
            } catch (\Throwable) {
                // Corrupt or stale cache file: fall through to an in-memory build.
            }
        }

        $container = self::build($botId, $botUsername, $botApiKey);
        $container->compile();
        return $container;
    }

    private static function loadCompiled(string $file): ContainerInterface
    {
        require_once $file;
        $class = self::COMPILED_CLASS;
        return new $class();
    }

    private static function build(int $botId, string $botUsername, string $botApiKey): ContainerBuilder
    {
        self::validateArguments($botId, $botUsername, $botApiKey);
        $whisperCppUrl = self::readConfig()['whisperCppUrl'];
        $scalarValues = self::scalarValues($botId, $botUsername, $botApiKey, $whisperCppUrl);

        $container = new ContainerBuilder();

        $loader = new PhpFileLoader($container, new FileLocator(self::projectDir() . '/config'));
        $loader->load('services.php');

        self::pruneNonAutowireable($container);
        self::applyScalarArguments($container, $scalarValues);

        return $container;
    }

    /**
     * Maps each globally-bindable constructor parameter name to its value.
     *
     * @return array<string, mixed>
     */
    private static function scalarValues(int $botId, string $botUsername, string $botApiKey, string $whisperCppUrl): array
    {
        return [
            'telegramBotId'       => $botId,
            'botUserId'           => $botId,
            'telegramBotUserId'   => $botId,
            'telegramBotUsername' => $botUsername,
            'telegramBotUserName' => $botUsername,
            'botUserName'         => $botUsername,
            'telegramBotApiKey'   => $botApiKey,
            'whisperCppUri'       => $whisperCppUrl,
            'name'                => self::DATABASE_NAME,
        ];
    }

    /**
     * Fills required scalar constructor parameters that autowiring cannot
     * resolve, for every remaining definition that declares one. Applied after
     * pruning so only bindable names used by the surviving services are set
     * (Symfony errors on unused _defaults bindings, which this avoids).
     *
     * @param array<string, mixed> $scalarValues
     */
    private static function applyScalarArguments(ContainerBuilder $container, array $scalarValues): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $constructor = (new ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                continue;
            }
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
                    continue;
                }
                $name = $parameter->getName();
                if (isset(self::BINDED_PARAM_NAMES[$name]) && array_key_exists($name, $scalarValues)) {
                    $definition->setArgument('$' . $name, $scalarValues[$name]);
                }
            }
        }
    }

    private static function dump(ContainerBuilder $container, string $file): void
    {
        $code = (new PhpDumper($container))->dump(['class' => self::COMPILED_CLASS]);
        file_put_contents($file, $code);
    }

    private static function readConfig(): array
    {
        $configString = file_get_contents(self::configPath());
        if ($configString === false) {
            throw new Exception('Failed to read ' . self::configPath());
        }
        $config = json_decode($configString, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !isset($config['whisperCppUrl'])) {
            throw new Exception('config.json must contain whisperCppUrl');
        }
        return $config;
    }

    private static function ensureCacheDir(): void
    {
        if (!is_dir(self::cacheDir()) && !@mkdir(self::cacheDir(), 0775, true) && !is_dir(self::cacheDir())) {
            throw new Exception(sprintf('Failed to create directory "%s"', self::cacheDir()));
        }
    }

    /**
     * Catches the classic "two strings in a row" swap early: a Telegram bot
     * token always starts with "<botId>:", and the username never contains a
     * colon. If this fails, warmup()/getContainer() were called with the
     * username and API key in the wrong order.
     */
    private static function validateArguments(int $botId, string $botUsername, string $botApiKey): void
    {
        if ($botApiKey === '' || !str_starts_with($botApiKey, $botId . ':')) {
            throw new Exception(sprintf(
                'Invalid bot API key for id %d (got %s)',
                $botId,
                $botApiKey === '' ? '(empty)' : $botApiKey,
            ));
        }
        if (str_contains($botUsername, ':')) {
            throw new Exception(sprintf(
                'Invalid bot username %s (must not contain ":")',
                $botUsername,
            ));
        }
    }

    private static function newestConfigMtime(): int
    {
        return max(
            filemtime(self::configPath()) ?: 0,
            filemtime(self::servicesPath()) ?: 0,
            filemtime(__FILE__) ?: 0,
        );
    }

    private static function newestSourceMtime(): int
    {
        $max = self::newestConfigMtime();
        $srcDir = self::projectDir() . '/src';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iter as $f) {
            if ($f->getExtension() === 'php') {
                $max = max($max, (int) $f->getMTime());
            }
        }
        return $max;
    }

    /**
     * Removes every service definition that cannot be cleanly autowired as a
     * shared singleton. Without this, compile() fails on the many classes with
     * unbindable scalar/array/closure constructor parameters (or that depend on
     * such classes). What remains is exactly the autowireable transitive
     * closure given the global scalar bindings.
     */
    private static function pruneNonAutowireable(ContainerBuilder $container): void
    {
        $candidateClasses = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isSynthetic()) {
                continue;
            }
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                $container->removeDefinition($id);
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait() || $reflection->isEnum()
                || $reflection->isSubclassOf(\Throwable::class)
                || in_array($class, self::SEMANTIC_EXCLUDE, true)
            ) {
                $container->removeDefinition($id);
                continue;
            }
            $candidateClasses[$class] = true;
        }

        $interfaceImplementations = self::collectInterfaceImplementations($candidateClasses);

        $resolvable = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($candidateClasses as $class => $_) {
                if (isset($resolvable[$class])) {
                    continue;
                }
                if (self::isResolvable($class, $resolvable, $interfaceImplementations)) {
                    $resolvable[$class] = true;
                    $changed = true;
                }
            }
        }

        foreach ($candidateClasses as $class => $_) {
            if (!isset($resolvable[$class])) {
                $container->removeDefinition($class);
            }
        }
    }

    /**
     * @param array<string,true>             $resolvable
     * @param array<string, list<string>>    $interfaceImplementations
     */
    private static function isResolvable(
        string $class,
        array $resolvable,
        array $interfaceImplementations,
    ): bool {
        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return true;
        }
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType) {
                return false;
            }
            if ($type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === 'array' || $typeName === 'callable') {
                    return false;
                }
                if (!isset(self::BINDED_PARAM_NAMES[$parameter->getName()])) {
                    return false;
                }
                continue;
            }
            $typeName = $type->getName();
            if (interface_exists($typeName)) {
                $resolvableImplementations = array_filter(
                    $interfaceImplementations[$typeName] ?? [],
                    static fn (string $impl): bool => isset($resolvable[$impl]),
                );
                if (count($resolvableImplementations) !== 1) {
                    return false;
                }
                continue;
            }
            if (!isset($resolvable[$typeName])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,true> $candidateClasses
     * @return array<string, list<string>>
     */
    private static function collectInterfaceImplementations(array $candidateClasses): array
    {
        $map = [];
        foreach ($candidateClasses as $class => $_) {
            try {
                $reflection = new ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            foreach ($reflection->getInterfaceNames() as $interface) {
                $map[$interface][] = $class;
            }
        }
        return $map;
    }
}
