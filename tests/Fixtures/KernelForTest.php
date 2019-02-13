<?php

declare(strict_types=1);

namespace Thruster\MikroKernel\Tests\Fixtures;

use Symfony\Component\Config\Loader\LoaderInterface;
use Thruster\MikroKernel\MikroKernel;

/**
 * Class KernelForTest.
 *
 * @author  Aurimas Niekis <aurimas@niekis.lt>
 */
class KernelForTest extends MikroKernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/Tests/Fixtures/cache.' . $this->environment;
    }
}
