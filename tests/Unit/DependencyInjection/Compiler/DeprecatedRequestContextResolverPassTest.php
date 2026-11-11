<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\Compiler\DeprecatedRequestContextResolverPass;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Tests\Kernel\DddHostContextResolver;
use Nowo\HttpLogBundle\Tests\Kernel\TestRequestContextResolver;
use Nowo\HttpLogBundle\Tests\Support\RecordingContextResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * The container build reports each resolver that implements only the deprecated `Request` port, and changes nothing.
 */
final class DeprecatedRequestContextResolverPassTest extends TestCase
{
    #[Test]
    public function onlyResolversWithoutTheNewPortAreReported(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('legacy.class', TestRequestContextResolver::class);
        $container->register('legacy', '%legacy.class%')->addTag(HttpLogRequestContextResolution::RESOLVER_TAG);
        $container->register(DddHostContextResolver::class)->addTag(HttpLogRequestContextResolution::RESOLVER_TAG);
        $container->register('recording', RecordingContextResolver::class)->addTag(HttpLogRequestContextResolution::RESOLVER_TAG);
        $container->register('missing', 'App\\DoesNotExist')->addTag(HttpLogRequestContextResolution::RESOLVER_TAG);
        $container->register('untagged', TestRequestContextResolver::class);

        $messages = [];
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            (new DeprecatedRequestContextResolverPass())->process($container);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $messages);
        self::assertStringContainsString(TestRequestContextResolver::class, $messages[0]);
        self::assertStringContainsString('HttpRequestContextResolverInterface', $messages[0]);
        self::assertStringContainsString('1.19', $messages[0]);
        self::assertSame(['legacy', DddHostContextResolver::class, 'recording', 'missing'], array_keys($container->findTaggedServiceIds(HttpLogRequestContextResolution::RESOLVER_TAG)));
    }
}
