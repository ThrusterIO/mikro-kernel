<?php

declare(strict_types=1);

namespace Thruster\MikroKernel\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Thruster\MikroKernel\MikroKernel;
use Thruster\MikroKernel\MikroKernelInterface;
use Thruster\MikroKernel\Tests\Fixtures\CustomProjectDirKernel;
use Thruster\MikroKernel\Tests\Fixtures\KernelForTest;

/**
 * Class MikroKernelTest.
 *
 * @author  Aurimas Niekis <aurimas@niekis.lt>
 */
class MikroKernelTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        $fs = new Filesystem();
        $fs->remove(__DIR__ . '/Fixtures/var');
    }

    public function testConstructor(): void
    {
        $env   = 'test_env';
        $debug = true;

        $kernel = new KernelForTest($env, $debug);

        $this->assertEquals($env, $kernel->getEnvironment());
        $this->assertEquals($debug, $kernel->isDebug());
        $this->assertFalse($kernel->isBooted());
        $this->assertNull($kernel->getContainer());
    }

    public function testClone(): void
    {
        $env   = 'test_env';
        $debug = true;

        $kernel = new KernelForTest($env, $debug);
        $clone  = clone $kernel;

        $this->assertEquals($env, $clone->getEnvironment());
        $this->assertEquals($debug, $clone->isDebug());
        $this->assertFalse($clone->isBooted());
        $this->assertNull($clone->getContainer());
    }

    public function testInitializeContainerClearsOldContainers(): void
    {
        $fs                 = new Filesystem();
        $legacyContainerDir = __DIR__ . '/Fixtures/var/cache/custom/ContainerA123456';
        $fs->mkdir($legacyContainerDir);
        touch($legacyContainerDir . '.legacy');

        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $containerDir = __DIR__ . '/Fixtures/var/cache/custom/' . substr(\get_class($kernel->getContainer()), 0, 16);
        $this->assertTrue(unlink(__DIR__ . '/Fixtures/var/cache/custom/Thruster_MikroKernel_Tests_Fixtures_CustomProjectDirKernelCustomDebugContainer.php.meta'));
        $this->assertFileExists($containerDir);
        $this->assertFileNotExists($containerDir . '.legacy');

        $kernel = new CustomProjectDirKernel(function ($container): void { $container->register('foo', 'stdClass')->setPublic(true); });
        $kernel->boot();

        $this->assertFileExists($containerDir);
        $this->assertFileExists($containerDir . '.legacy');
        $this->assertFileNotExists($legacyContainerDir);
        $this->assertFileNotExists($legacyContainerDir . '.legacy');
    }

    public function testBootSetsTheBootedFlagToTrue(): void
    {
        // use test kernel to access isBooted()
        $kernel = $this->getKernelForTest(['initializeContainer']);

        $kernel->boot();

        $this->assertTrue($kernel->isBooted());
    }

    public function testBootKernelSeveralTimesOnlyInitializesBundlesOnce(): void
    {
        $kernel = $this->getKernel(['initializeContainer']);

        $kernel->expects($this->once())
            ->method('initializeContainer');

        $kernel->boot();
        $kernel->boot();
    }

    public function testHandleCallsHandleWithRequestHandler(): void
    {
        $serverRequest      = $this->getMockForAbstractClass(ServerRequestInterface::class);
        $requestHandlerMock = $this->getMockBuilder(RequestHandlerInterface::class)
            ->setMethods(['handle'])
            ->getMockForAbstractClass();

        $requestHandlerMock->expects($this->exactly(2))
            ->method('handle')
            ->with($serverRequest);

        $containerMock = $this->getMockBuilder(ContainerInterface::class)
            ->setMethods(['has', 'get'])
            ->getMockForAbstractClass();

        $containerMock->expects($this->once())
            ->method('has')
            ->willReturn(true);

        $containerMock->expects($this->once())
            ->method('get')
            ->with(RequestHandlerInterface::class)
            ->willReturn($requestHandlerMock);

        $kernel = new CustomProjectDirKernel();

        $p = new \ReflectionProperty($kernel, 'container');
        $p->setAccessible(true);
        $p->setValue($kernel, $containerMock);

        $kernel->handle($serverRequest);
        $kernel->handle($serverRequest);
    }

    public function testHandleCallsHandleWithoutRequestHandler(): void
    {
        $serverRequest = $this->getMockForAbstractClass(ServerRequestInterface::class);

        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $response = $kernel->handle($serverRequest);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('<h1>It Works!</h1>', $response->getBody()->getContents());
    }

    public function testSerialize(): void
    {
        $env      = 'test_env';
        $debug    = true;
        $kernel   = new KernelForTest($env, $debug);
        $expected = serialize([$env, $debug]);
        $this->assertEquals($expected, $kernel->serialize());
    }

    protected function getKernel(array $methods = []): MikroKernel
    {
        return $this
            ->getMockBuilder(MikroKernel::class)
            ->setMethods($methods)
            ->setConstructorArgs(['test', false])
            ->getMockForAbstractClass();
    }

    protected function getKernelForTest(array $methods = [], $debug = false): MikroKernelInterface
    {
        return $this->getMockBuilder(KernelForTest::class)
            ->setConstructorArgs(['test', $debug])
            ->setMethods($methods)
            ->getMock();
    }
}
