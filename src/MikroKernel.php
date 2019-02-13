<?php

declare(strict_types=1);

namespace Thruster\MikroKernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\ProxyManager\LazyProxy\Instantiator\RuntimeInstantiator;
use Symfony\Bridge\ProxyManager\LazyProxy\PhpDumper\ProxyDumper;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Debug\DebugClassLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\ClosureLoader;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Thruster\HttpFactory\HttpFactoryInterface;
use Thruster\HttpFactory\ZendDiactorosHttpFactory;

/**
 * Class MikroKernel.
 *
 * @author  Aurimas Niekis <aurimas@niekis.lt>
 */
abstract class MikroKernel implements MikroKernelInterface
{
    /** @var ContainerInterface */
    protected $container;

    /** @var string */
    protected $environment;

    /** @var bool */
    protected $debug;

    /** @var bool */
    protected $booted;

    /** @var int */
    protected $startTime;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $warmupDir;

    /** @var RequestHandlerInterface */
    private $requestHandler;

    public function __construct(string $environment = 'dev', bool $debug = true)
    {
        $this->environment = $environment;
        $this->debug       = $debug;
        $this->booted      = false;
    }

    public function __clone()
    {
        $this->booted    = false;
        $this->container = null;
        $this->startTime = null;
    }

    /**
     * {@inheritdoc}
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        if ($this->debug) {
            $this->startTime = time();
        }

        if ($this->debug && !isset($_ENV['SHELL_VERBOSITY']) && !isset($_SERVER['SHELL_VERBOSITY'])) {
            putenv('SHELL_VERBOSITY=3');
            $_ENV['SHELL_VERBOSITY']    = 3;
            $_SERVER['SHELL_VERBOSITY'] = 3;
        }

        // init container
        $this->initializeContainer();

        $this->booted = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestHandler = $this->getRequestHandler();

        if (null !== $requestHandler) {
            return $requestHandler->handle($request);
        }

        return $this->getHttpFactory()
            ->response()
            ->createResponse()
            ->withBody($this->getHttpFactory()->stream()->createStream('<h1>It Works!</h1>'));
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * {@inheritdoc}
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Gets the application root dir (path of the project's composer file).
     *
     * @return string The project root dir
     */
    public function getProjectDir(): string
    {
        if (null === $this->projectDir) {
            $r   = new \ReflectionObject($this);
            $dir = $rootDir = \dirname($r->getFileName());
            while (!file_exists($dir . '/composer.json')) {
                if ($dir === \dirname($dir)) {
                    return $this->projectDir = $rootDir;
                }
                $dir = \dirname($dir);
            }
            $this->projectDir = $dir;
        }

        return $this->projectDir;
    }

    /**
     * {@inheritdoc}
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    public function getRequestHandler(): ?RequestHandlerInterface
    {
        if (null !== $this->requestHandler) {
            return $this->requestHandler;
        }

        if (false === $this->container->has(RequestHandlerInterface::class)) {
            return null;
        }

        $this->requestHandler = $this->container->get(RequestHandlerInterface::class);

        return $this->requestHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function getStartTime(): int
    {
        return $this->debug ? $this->startTime : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    /**
     * Use this method to register compiler passes and manipulate the container during the building process.
     *
     * @param ContainerBuilder $container
     */
    protected function build(ContainerBuilder $container): void
    {
    }

    /**
     * Gets the container class.
     *
     * @return string The container class
     */
    protected function getContainerClass(): string
    {
        $class = \get_class($this);
        if ('c' === $class[0] && 0 === strpos($class, "class@anonymous\0")) {
            $class = \get_parent_class($class) . str_replace('.', '_', ContainerBuilder::hash($class));
        }

        return str_replace('\\', '_', $class) .
            ucfirst($this->environment) . ($this->debug ? 'Debug' : '') . 'Container';
    }

    /**
     * Gets the container's base class.
     *
     * All names except Container must be fully qualified.
     *
     * @return string
     */
    protected function getContainerBaseClass(): string
    {
        return 'Container';
    }

    /**
     * Initializes the service container.
     *
     * The cached version of the service container is used when fresh, otherwise the
     * container is built.
     */
    protected function initializeContainer(): void
    {
        $class        = $this->getContainerClass();
        $cacheDir     = $this->warmupDir ?: $this->getCacheDir();
        $cache        = new ConfigCache($cacheDir . '/' . $class . '.php', $this->debug);
        $oldContainer = null;

        if ($fresh = $cache->isFresh()) {
            // Silence E_WARNING to ignore "include" failures - don't use "@" to prevent silencing fatal errors
            $errorLevel = error_reporting(\E_ALL ^ \E_WARNING);
            $fresh      = $oldContainer = false;

            try {
                if (file_exists($cache->getPath()) && \is_object($this->container = include $cache->getPath())) {
                    $this->container->set('kernel', $this);
                    $oldContainer = $this->container;
                    $fresh        = true;
                }
            } catch (\Throwable $e) {
            } catch (\Exception $e) {
            } finally {
                error_reporting($errorLevel);
            }
        }

        if ($fresh) {
            return;
        }

        if ($this->debug) {
            $collectedLogs   = [];
            $previousHandler = \defined('PHPUNIT_COMPOSER_INSTALL');
            $previousHandler = $previousHandler ?: set_error_handler(function ($type, $message, $file, $line) use (
                &$collectedLogs,
                &$previousHandler
            ) {
                if (E_USER_DEPRECATED !== $type && E_DEPRECATED !== $type) {
                    /* @var callable $previousHandler */
                    return $previousHandler ? $previousHandler($type, $message, $file, $line) : false;
                }

                if (isset($collectedLogs[$message])) {
                    $collectedLogs[$message]['count']++;

                    return;
                }

                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                // Clean the trace by removing first frames added by the error handler itself.
                for ($i = 0; isset($backtrace[$i]); $i++) {
                    if ($backtrace[$i]['line'] ?? null === $line && $backtrace[$i]['file'] ?? null === $file) {
                        $backtrace = \array_slice($backtrace, 1 + $i);
                        break;
                    }
                }
                // Remove frames added by DebugClassLoader.
                for ($i = \count($backtrace) - 2; 0 < $i; $i--) {
                    if (DebugClassLoader::class === ($backtrace[$i]['class'] ?? null)) {
                        $backtrace = [$backtrace[$i + 1]];
                        break;
                    }
                }

                $collectedLogs[$message] = [
                    'type'    => $type,
                    'message' => $message,
                    'file'    => $file,
                    'line'    => $line,
                    'trace'   => [$backtrace[0]],
                    'count'   => 1,
                ];
            });
        }

        try {
            $container = null;
            $container = $this->buildContainer();
            $container->compile();
        } finally {
            if ($this->debug && true !== $previousHandler) {
                restore_error_handler();

                file_put_contents(
                    $cacheDir . '/' . $class . 'Deprecations.log',
                    serialize(array_values($collectedLogs))
                );
                file_put_contents(
                    $cacheDir . '/' . $class . 'Compiler.log',
                    null !== $container ? implode("\n", $container->getCompiler()->getLog()) : ''
                );
            }
        }

        if (null === $oldContainer && file_exists($cache->getPath())) {
            $errorLevel = error_reporting(\E_ALL ^ \E_WARNING);

            try {
                $oldContainer = include $cache->getPath();
            } catch (\Throwable $e) {
            } catch (\Exception $e) {
            } finally {
                error_reporting($errorLevel);
            }
        }

        $oldContainer = \is_object($oldContainer) ? new \ReflectionClass($oldContainer) : false;

        $this->dumpContainer($cache, $container, $class, $this->getContainerBaseClass());
        $this->container = require $cache->getPath();
        $this->container->set('kernel', $this);

        if ($oldContainer && \get_class($this->container) !== $oldContainer->name) {
            // Because concurrent requests might still be using them,
            // old container files are not removed immediately,
            // but on a next dump of the container.
            static $legacyContainers = [];
            $oldContainerDir         = \dirname($oldContainer->getFileName());

            $legacyContainers[$oldContainerDir . '.legacy'] = true;
            foreach (glob(\dirname($oldContainerDir) . \DIRECTORY_SEPARATOR . '*.legacy') as $legacyContainer) {
                if (!isset($legacyContainers[$legacyContainer]) && @unlink($legacyContainer)) {
                    (new Filesystem())->remove(substr($legacyContainer, 0, -7));
                }
            }

            touch($oldContainerDir . '.legacy');
        }

        if ($this->container->has('cache_warmer')) {
            $this->container->get('cache_warmer')->warmUp($this->container->getParameter('kernel.cache_dir'));
        }
    }

    /**
     * Returns the kernel parameters.
     *
     * @return array An array of kernel parameters
     */
    protected function getKernelParameters(): array
    {
        return [
            'kernel.project_dir'     => realpath($this->getProjectDir()) ?: $this->getProjectDir(),
            'kernel.environment'     => $this->environment,
            'kernel.debug'           => $this->debug,
            'kernel.cache_dir'       => realpath($cacheDir = $this->warmupDir ?: $this->getCacheDir()) ?: $cacheDir,
            'kernel.container_class' => $this->getContainerClass(),
        ];
    }

    /**
     * Builds the service container.
     *
     * @return ContainerBuilder The compiled service container
     *
     * @throws \RuntimeException
     */
    protected function buildContainer()
    {
        $dir = $this->warmupDir ?: $this->getCacheDir();
        if (!is_dir($dir)) {
            if (false === @mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf("Unable to create the cache directory (%s)\n", $dir));
            }
        } elseif (!is_writable($dir)) {
            throw new \RuntimeException(sprintf("Unable to write in the cache directory (%s)\n", $dir));
        }

        $container = $this->getContainerBuilder();
        $container->addObjectResource($this);
        $this->build($container);

        $this->registerContainerConfiguration($this->getContainerLoader($container));

        return $container;
    }

    /**
     * Gets a new ContainerBuilder instance used to build the service container.
     *
     * @return ContainerBuilder
     */
    protected function getContainerBuilder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->getParameterBag()->add($this->getKernelParameters());

        if ($this instanceof CompilerPassInterface) {
            $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, -10000);
        }

        if (class_exists('ProxyManager\Configuration') &&
            class_exists('Symfony\Bridge\ProxyManager\LazyProxy\Instantiator\RuntimeInstantiator')
        ) {
            $container->setProxyInstantiator(new RuntimeInstantiator());
        }

        $container->register(HttpFactoryInterface::class, $this->getHttpFactoryClass())
            ->setPublic(true);

        $container->setAlias('http_factory', HttpFactoryInterface::class);

        return $container;
    }

    public function getHttpFactory(): HttpFactoryInterface
    {
        return $this->container->get(HttpFactoryInterface::class);
    }

    public function getHttpFactoryClass(): string
    {
        return ZendDiactorosHttpFactory::class;
    }

    /**
     * Dumps the service container to PHP code in the cache.
     *
     * @param ConfigCache      $cache     The config cache
     * @param ContainerBuilder $container The service container
     * @param string           $class     The name of the class to generate
     * @param string           $baseClass The name of the container's base class
     */
    protected function dumpContainer(ConfigCache $cache, ContainerBuilder $container, $class, $baseClass): void
    {
        // cache the container
        $dumper = new PhpDumper($container);

        if (class_exists('ProxyManager\Configuration') &&
            class_exists('Symfony\Bridge\ProxyManager\LazyProxy\PhpDumper\ProxyDumper')
        ) {
            $dumper->setProxyDumper(new ProxyDumper());
        }

        if ($container->hasParameter('kernel.container_build_time')) {
            $buildTime =  $container->getParameter('kernel.container_build_time');
        }

        $content = $dumper->dump([
            'class'      => $class,
            'base_class' => $baseClass,
            'file'       => $cache->getPath(),
            'as_files'   => true,
            'debug'      => $this->debug,
            'build_time' => $buildTime ?? time(),
        ]);

        $rootCode = array_pop($content);
        $dir      = \dirname($cache->getPath()) . '/';
        $fs       = new Filesystem();

        foreach ($content as $file => $code) {
            $fs->dumpFile($dir . $file, $code);
            @chmod($dir . $file, 0666 & ~umask());
        }

        $legacyFile = \dirname($dir . $file) . '.legacy';
        if (file_exists($legacyFile)) {
            @unlink($legacyFile);
        }

        $cache->write($rootCode, $container->getResources());
    }

    /**
     * Returns a loader for the container.
     *
     * @param ContainerInterface $container
     *
     * @return DelegatingLoader The loader
     */
    protected function getContainerLoader(ContainerInterface $container)
    {
        $locator  = new FileLocator($this);
        $resolver = new LoaderResolver([
            new XmlFileLoader($container, $locator),
            new YamlFileLoader($container, $locator),
            new IniFileLoader($container, $locator),
            new PhpFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
            new ClosureLoader($container),
        ]);

        return new DelegatingLoader($resolver);
    }

    public function serialize()
    {
        return serialize([$this->environment, $this->debug]);
    }

    public function unserialize($data): void
    {
        [$environment, $debug] = unserialize($data, ['allowed_classes' => false]);

        $this->__construct($environment, $debug);
    }
}
