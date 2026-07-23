<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Perk11\Viktor89\Log\Viktor89Logger;
use Psr\Log\LoggerInterface;

/**
 * DI configuration loaded by PhpFileLoader.
 *
 * The whole Perk11\Viktor89 namespace is registered with autowiring, then
 * ContainerFactory prunes every class that cannot be cleanly autowired
 * (per-message state, multiple instances with different config, closures,
 * scalar/array-only value objects, exceptions, ...) and binds the global
 * scalar arguments (bot id, username, ...) to the services that need them.
 * What remains are the stateless singleton services that are safe to share.
 */
return function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('Perk11\\Viktor89\\', __DIR__ . '/../src/');

    // Single autowired Psr\Log\LoggerInterface implementation for the whole bot.
    $services->alias(LoggerInterface::class, Viktor89Logger::class);
};
