<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\TestCase;

/**
 * Loads every class/interface/trait/enum under src/. This mirrors what the DI
 * container does at startup (it autowires the whole Perk11\Viktor89\ namespace),
 * so a class-definition fatal that only appears at load time — and that `php -l`
 * cannot catch (e.g. an illegal property override such as a subclass weakening
 * the visibility of an inherited property) — fails here instead of crashing the
 * whole service with status 255.
 */
class SourceClassesLoadTest extends TestCase
{
    public function testEverySourceClassLoads(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $this->assertDirectoryExists($srcDir);

        $loaded = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($srcDir . '/'));
            $class = 'Perk11\\Viktor89\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            $this->assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
                "Expected $class to be loadable (matches {$file->getPathname()})",
            );
            $loaded++;
        }

        $this->assertGreaterThan(0, $loaded, 'No source classes were found to load.');
    }
}
