<?php

declare(strict_types=1);

namespace Thruster\MikroKernel\Tests\Fixtures;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Thruster\MikroKernel\MikroKernel;

/**
 * Class CustomProjectDirKernel.
 *
 * @author  Aurimas Niekis <aurimas@niekis.lt>
 */
class CustomProjectDirKernel extends MikroKernel
{
    private $buildContainer;

    public function __construct(\Closure $buildContainer = null, $env = 'custom')
    {
        parent::__construct($env);
        $this->buildContainer = $buildContainer;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/../Fixtures';
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($build = $this->buildContainer) {
            $build($container);
        }
    }
}
