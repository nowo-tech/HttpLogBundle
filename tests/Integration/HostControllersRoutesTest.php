<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Tests\Kernel\HostControllersRoutesKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function sprintf;

/**
 * REQ-SF-004 rule 7: the admin routes are opt-in (import of the bundle routes.yaml); the host's default
 * `routing.controllers` resource must not register them.
 */
final class HostControllersRoutesTest extends TestCase
{
    private const ROUTES = [
        'nowo_http_log_admin_index',
        'nowo_http_log_admin_show',
        'nowo_http_log_admin_export',
        'nowo_http_log_admin_export_download',
        'nowo_http_log_admin_purge',
        'nowo_http_log_admin_delete',
    ];

    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }
    }

    public function testAdminRoutesAreNotRegisteredWithoutTheRoutesImport(): void
    {
        $kernel = new HostControllersRoutesKernel('test', true);
        $kernel->boot();

        try {
            $routes = $this->router($kernel)->getRouteCollection();
            foreach (self::ROUTES as $name) {
                self::assertNull($routes->get($name), sprintf('Route "%s" must only exist when the host imports the bundle routes.yaml.', $name));
            }
            foreach ($routes as $name => $route) {
                self::assertStringStartsNotWith('nowo_http_log', $name);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testImportedRoutesUseTheControllerWithItsServiceLocator(): void
    {
        $kernel = new HostControllersRoutesKernel('test', true, true);
        $kernel->boot();

        try {
            $routes = $this->router($kernel)->getRouteCollection();
            foreach (self::ROUTES as $name) {
                self::assertNotNull($routes->get($name));
            }
            self::assertSame('/admin/http-log', $routes->get('nowo_http_log_admin_index')?->getPath());

            // Not autoconfigured: the explicit container.service_subscriber tag still gives AbstractController its locator.
            $controller = $kernel->getContainer()->get('test.service_container')->get(HttpLogAdminController::class);
            self::assertInstanceOf(HttpLogAdminController::class, $controller);
            $locator = (new ReflectionProperty(AbstractController::class, 'container'))->getValue($controller);
            self::assertInstanceOf(ContainerInterface::class, $locator);
            self::assertTrue($locator->has('twig'));
            self::assertTrue($locator->has('form.factory'));
        } finally {
            $kernel->shutdown();
        }
    }

    private function router(HostControllersRoutesKernel $kernel): RouterInterface
    {
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
