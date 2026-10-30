<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\HttpLogBundle\NowoHttpLogBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getmypid;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Test kernel whose routing is the Symfony default `controllers: { resource: routing.controllers }`.
 * With $importBundleRoutes it also imports @NowoHttpLogBundle/Resources/config/routes.yaml, as hosts do
 * (REQ-SF-004 rule 7: the admin routes exist only through that import).
 */
final class HostControllersRoutesKernel extends Kernel
{
    use MicroKernelTrait;

    private const CACHE_NAMESPACE = 'nowo-http-log-bundle-routes';

    public function __construct(string $environment, bool $debug, private readonly bool $importBundleRoutes = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new NowoHttpLogBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        // One cache per PHPUnit process and routing variant (never reused across runs with other service definitions).
        return sys_get_temp_dir() . '/' . self::CACHE_NAMESPACE . '/' . getmypid() . '/host-controllers-' . ($this->importBundleRoutes ? 'imported-' : '') . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/' . self::CACHE_NAMESPACE . '/' . getmypid() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'test-secret',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
            'property_info'         => ['enabled' => false],
            'csrf_protection'       => true,
            'form'                  => ['enabled' => true],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('security', [
            'providers' => ['memory' => ['memory' => ['users' => []]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'memory']],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => $this->ormConfig(),
        ]);
        $container->extension('nowo_http_log', [
            'web_ui' => ['enabled' => true, 'path_prefix' => '/admin/http-log'],
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');

        if ($this->importBundleRoutes) {
            $routes->import('@NowoHttpLogBundle/Resources/config/routes.yaml');
        }
    }

    /**
     * ORM 3 on PHP 8.4+: native lazy objects (DoctrineBundle 2.x needs the option, 3.x deprecates it).
     *
     * @return array<string, mixed>
     */
    private function ormConfig(): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }
        $root = (new DoctrineConfiguration(false))->getConfigTreeBuilder()->buildTree();
        $orm  = $root instanceof ArrayNode ? ($root->getChildren()['orm'] ?? null) : null;
        $node = $orm instanceof ArrayNode ? ($orm->getChildren()['enable_native_lazy_objects'] ?? null) : null;

        return $node instanceof BaseNode && !$node->isDeprecated() ? ['enable_native_lazy_objects' => true] : [];
    }
}
