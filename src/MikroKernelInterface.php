<?php

declare(strict_types=1);

namespace Thruster\MikroKernel;

use Psr\Http\Server\RequestHandlerInterface;
use Serializable;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Thruster\HttpFactory\HttpFactoryInterface;

/**
 * Class MikroKernelInterface.
 *
 * @author  Aurimas Niekis <aurimas@niekis.lt>
 */
interface MikroKernelInterface extends RequestHandlerInterface, Serializable
{
    /**
     * Loads the container configuration.
     *
     * @param LoaderInterface $loader
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void;

    /**
     * Boots the current kernel.
     */
    public function boot(): void;

    /**
     * Gets the environment.
     *
     * @return string The current environment
     */
    public function getEnvironment(): string;

    /**
     * Checks if debug mode is enabled.
     *
     * @return bool true if debug mode is enabled, false otherwise
     */
    public function isDebug(): bool;

    /**
     * Gets the current container.
     *
     * @return ContainerInterface|null A ContainerInterface instance or null when the Kernel is shutdown
     */
    public function getContainer(): ?ContainerInterface;

    /**
     * Gets the kernel start time (not available if debug is disabled).
     *
     * @return int The request start timestamp
     */
    public function getStartTime(): int;

    /**
     * Gets the cache directory.
     *
     * @return string The cache directory
     */
    public function getCacheDir(): string;

    /**
     * Gets HttpFactoryInstance.
     *
     * @return HttpFactoryInterface
     */
    public function getHttpFactory(): HttpFactoryInterface;
}
