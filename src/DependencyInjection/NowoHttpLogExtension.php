<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use LogicException;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function is_string;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoHttpLogExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths'     => [__DIR__ . '/../Resources/translations'],
                    'fallbacks' => ['en'],
                ],
            ]);
        }

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoHttpLogBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }

        $configs = $container->getExtensionConfig($this->getAlias());
        if ($configs === []) {
            return;
        }

        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['web_ui']['enabled'] && $container->hasExtension('twig')) {
            // Escape leading "@" so DI does not treat Twig logical names as service refs.
            $layoutTemplate = $config['web_ui']['layout_template'];
            if (is_string($layoutTemplate) && str_starts_with($layoutTemplate, '@')) {
                $layoutTemplate = '@' . $layoutTemplate;
            }

            $container->prependExtensionConfig('twig', [
                'globals' => [
                    'nowo_http_log_layout_template' => $layoutTemplate,
                    'nowo_http_log_css_framework'   => $config['web_ui']['css_framework'],
                    'nowo_http_log_path_prefix'     => $config['web_ui']['path_prefix'],
                    'nowo_http_log_page_size'       => $config['web_ui']['page_size'],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (
            $config['web_ui']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoHttpLogBundle web UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $alias = Configuration::ALIAS;
        $container->setParameter($alias . '.enabled', $config['enabled']);
        $container->setParameter($alias . '.environments', $config['environments']);
        $container->setParameter($alias . '.async', $config['async']);
        $container->setParameter($alias . '.sampling_rate', $config['sampling_rate']);
        $container->setParameter($alias . '.track_sub_requests', $config['track_sub_requests']);
        $container->setParameter($alias . '.ignore_routes', $config['ignore_routes']);
        $container->setParameter($alias . '.ignore_path_prefixes', $config['ignore_path_prefixes']);
        $container->setParameter($alias . '.capture', $config['capture']);
        $container->setParameter($alias . '.redaction', $config['redaction']);
        $container->setParameter($alias . '.retention', $config['retention']);
        $container->setParameter($alias . '.export', $config['export']);
        $container->setParameter($alias . '.export.max_sync_rows', $config['export']['max_sync_rows']);
        $container->setParameter($alias . '.redaction.headers', $config['redaction']['headers']);
        $container->setParameter($alias . '.redaction.query_params', $config['redaction']['query_params']);
        $container->setParameter($alias . '.redaction.json_paths', $config['redaction']['json_paths']);
        $container->setParameter($alias . '.web_ui', $config['web_ui']);
        $container->setParameter($alias . '.web_ui.enabled', $config['web_ui']['enabled']);
        $container->setParameter($alias . '.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter($alias . '.web_ui.page_size', $config['web_ui']['page_size']);
        $container->setParameter($alias . '.security', $config['security']);
        $container->setParameter($alias . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);

        if (!$config['web_ui']['enabled'] && $container->hasDefinition(HttpLogAdminController::class)) {
            $container->removeDefinition(HttpLogAdminController::class);
        }

        $this->registerAccessChecker($container, $config['security']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_http_log.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableHttpLogAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $security['access_roles']));
        }

        $container->setAlias(HttpLogAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }
}
