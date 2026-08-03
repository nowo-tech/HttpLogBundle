<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use Nowo\HttpLogBundle\Enum\CssFramework;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_int;
use function is_string;

/**
 * Configuration tree for {@see NowoHttpLogExtension}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_http_log';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('environments')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Empty list means all environments')
                ->end()
                ->booleanNode('async')->defaultTrue()->end()
                ->floatNode('sampling_rate')
                    ->defaultValue(1.0)
                    ->min(0.0)
                    ->max(1.0)
                ->end()
                ->booleanNode('track_sub_requests')->defaultFalse()->end()
                ->arrayNode('ignore_routes')
                    ->scalarPrototype()->end()
                    ->defaultValue(['_wdt', '_profiler', 'web_profiler*', 'nowo_http_log_*'])
                ->end()
                ->arrayNode('ignore_path_prefixes')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/admin/http-log'])
                ->end()
                ->arrayNode('capture')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('request_headers')->defaultTrue()->end()
                        ->booleanNode('request_body')->defaultFalse()->end()
                        ->booleanNode('response_headers')->defaultTrue()->end()
                        ->booleanNode('client_ip')->defaultTrue()->end()
                        ->booleanNode('user')->defaultTrue()->end()
                        ->integerNode('max_body_bytes')
                            ->defaultValue(65536)
                            ->min(0)
                        ->end()
                        ->arrayNode('response_body_by_type')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('html')->defaultFalse()->end()
                                ->booleanNode('json')->defaultTrue()->end()
                                ->booleanNode('soap')->defaultTrue()->end()
                                ->booleanNode('xml')->defaultTrue()->end()
                                ->booleanNode('text')->defaultFalse()->end()
                                ->booleanNode('binary')->defaultFalse()->end()
                                ->booleanNode('other')->defaultFalse()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('headers')
                            ->scalarPrototype()->end()
                            ->defaultValue(['Authorization', 'Cookie', 'Set-Cookie', 'X-Api-Key'])
                        ->end()
                        ->arrayNode('query_params')
                            ->scalarPrototype()->end()
                            ->defaultValue(['password', 'token', 'api_key'])
                        ->end()
                        ->arrayNode('json_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['*.password', '*.token'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('days')
                            ->defaultValue(30)
                            ->info('Null keeps entries forever')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_int($v) || $v < 0))
                                ->thenInvalid('Retention days must be a non-negative integer or null')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('formats')
                            ->scalarPrototype()->end()
                            ->defaultValue(['csv', 'json'])
                        ->end()
                        ->integerNode('max_sync_rows')->defaultValue(1000)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('path_prefix')
                            ->defaultValue('/admin/http-log')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !str_starts_with($v, '/'))
                                ->thenInvalid('web_ui.path_prefix must start with "/"')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultValue('@NowoHttpLogBundle/layout.html.twig')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(array_map(static fn (CssFramework $f): string => $f->value, CssFramework::cases()))
                            ->defaultValue(CssFramework::Bootstrap5->value)
                        ->end()
                        ->integerNode('page_size')
                            ->defaultValue(50)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')->defaultNull()->end()
                        ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
