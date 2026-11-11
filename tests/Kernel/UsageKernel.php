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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getmypid;
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Test kernel for the HL-1 usage features: `nowo_http_log` configuration given by the test, plus FrameworkBundle
 * HttpClient (with a scoped client), Mailer and Messenger when asked for. One cache directory per configuration.
 */
final class UsageKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $httpLog `nowo_http_log` configuration
     * @param array<string, mixed> $framework extra `framework` configuration (http_client, mailer, messenger…)
     * @param (callable(ContainerBuilder): void)|null $build extra services
     */
    public function __construct(
        private readonly array $httpLog = [],
        private readonly array $framework = [],
        private $build = null,
        private readonly string $variant = '',
    ) {
        parent::__construct('test', false);
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
        return sys_get_temp_dir() . '/nowo-http-log-usage-kernel/' . getmypid() . '/' . md5(serialize([$this->httpLog, $this->framework, $this->variant]));
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-http-log-usage-kernel/' . getmypid() . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->build !== null) {
            ($this->build)($container);
        }
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
        ] + $this->framework);
        $container->extension('security', [
            'providers' => ['memory' => ['memory' => ['users' => []]]],
            // /api: an eager, stateless firewall (the named-channel resolver may run before the controller);
            // everything else: the lazy main firewall
            'firewalls' => [
                'api'  => ['pattern' => '^/api', 'stateless' => true, 'provider' => 'memory'],
                'main' => ['lazy' => true, 'provider' => 'memory'],
            ],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => $this->ormConfig(),
        ]);
        $container->extension('nowo_http_log', $this->httpLog + ['web_ui' => ['enabled' => false]]);
        $container->services()->set(UsageTestController::class)->public()->tag('controller.service_arguments');
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('usage_hello', '/hello')->controller([UsageTestController::class, 'hello']);
        // LY-L2: erasure inside a request (PersonalDataEraseTestController is registered by that test)
        $routes->add('gdpr_erase_my_ip', '/gdpr/erase-my-ip')->controller([PersonalDataEraseTestController::class, 'eraseMyIp']);
        // HL-1 S4: routes of the named-channel tests (ApiTestController is registered by those tests)
        $routes->add('api_task', '/api/tasks/{id}')->controller([ApiTestController::class, 'task']);
        $routes->add('api_share', '/api/share/{token}')->controller([ApiTestController::class, 'share']);
        $routes->add('api_sync', '/api/sync')->controller([ApiTestController::class, 'sync']);
        $routes->add('api_ingest', '/api/ingest')->controller([ApiTestController::class, 'ok']);
        $routes->add('hooks_stripe', '/hooks/stripe')->controller([ApiTestController::class, 'ok'])->methods(['POST']);
        $routes->add('mcp_endpoint', '/mcp')->controller([ApiTestController::class, 'sync']);
    }

    /** @return array<string, mixed> */
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
