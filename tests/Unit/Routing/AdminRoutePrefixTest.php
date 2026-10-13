<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Routing;

use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * web_ui.path_prefix is the admin routes' prefix (it used to be hard-coded to /admin/http-log).
 */
final class AdminRoutePrefixTest extends TestCase
{
    #[Test]
    public function adminRoutesUseTheConfiguredPathPrefix(): void
    {
        $routes = $this->router('/ops/requests')->getRouteCollection();

        self::assertSame('/ops/requests', $routes->get('nowo_http_log_admin_index')?->getPath());
        self::assertSame('/ops/requests/{id}', $routes->get('nowo_http_log_admin_show')?->getPath());
        self::assertSame('/ops/requests/export', $routes->get('nowo_http_log_admin_export')?->getPath());
        self::assertSame('/ops/requests/purge', $routes->get('nowo_http_log_admin_purge')?->getPath());
        self::assertSame('/ops/requests/{id}/delete', $routes->get('nowo_http_log_admin_delete')?->getPath());
    }

    #[Test]
    public function defaultPrefixKeepsTheHistoricalPaths(): void
    {
        $routes = $this->router('/admin/http-log')->getRouteCollection();

        self::assertSame('/admin/http-log', $routes->get('nowo_http_log_admin_index')?->getPath());
        self::assertSame('/admin/http-log/{id}', $routes->get('nowo_http_log_admin_show')?->getPath());
    }

    private function router(string $prefix): Router
    {
        $container = new Container(new ParameterBag([
            'nowo_http_log.web_ui.path_prefix' => $prefix,
            'kernel.build_dir'                 => sys_get_temp_dir(),
            'kernel.container_class'           => 'HttpLogRoutePrefixTestContainer',
        ]));
        $container->set('routing.loader', new AttributeRouteControllerLoader());

        return new Router($container, HttpLogAdminController::class, ['resource_type' => 'attribute']);
    }
}
