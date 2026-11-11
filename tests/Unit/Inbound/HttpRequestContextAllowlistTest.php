<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextFactory;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RecordingContextResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * `redaction.context_attributes` (1.20): the host's explicit allowlist of raw request attributes, objects included,
 * readable through `HttpRequestContext::allowlistedAttribute()` and never visible in a dump of the context.
 */
final class HttpRequestContextAllowlistTest extends TestCase
{
    private function request(): Request
    {
        $request = Request::create('/api/tasks/42', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->attributes->add([
            '_route'              => 'api_task',
            '_route_params'       => ['id' => '42'],
            'credential_id'       => 'pt-7',
            'api_token'           => 'REDACTED-TEST-x',
            '_app_api_credential' => $this->credential(),
            'object'              => new HttpLogRequestContext(),
            'nullable'            => null,
        ]);

        return $request;
    }

    private function credential(): stdClass
    {
        $credential       = new stdClass();
        $credential->kind = 'personal_token';
        $credential->id   = 'pt-7';
        $credential->hint = 'REDACTED-TEST-x';

        return $credential;
    }

    #[Test]
    public function theDefaultEmptyAllowlistBuildsExactlyThe119Context(): void
    {
        $request = $this->request();
        $before  = (new HttpRequestContextFactory(['X-Api-Key'], HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS))->create($request, null, null);
        $after   = (new HttpRequestContextFactory(['X-Api-Key'], HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS, []))->create($request, null, null);

        self::assertEquals($before, $after);
        self::assertSame(['credential_id' => 'pt-7', 'nullable' => null], $after->attributes, 'the 1.19 scalar rule is unchanged');
        self::assertSame([], (new HttpRequestContextFactory())->allowedAttributes());
        foreach (['_app_api_credential', 'object', 'api_token', 'credential_id', 'nullable'] as $name) {
            self::assertNull($after->allowlistedAttribute($name), $name);
            self::assertFalse($after->hasAllowlistedAttribute($name), $name);
        }
        self::assertNull($after->attribute('object'));
        self::assertNull($after->attribute('api_token'), 'MR-H-L2 still drops a secret-like scalar copied by default');
    }

    #[Test]
    public function anAllowlistedAttributeIsGivenRawAndTheExplicitListWinsOverTheNameRules(): void
    {
        $request = $this->request();
        $factory = new HttpRequestContextFactory([], HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS, ['_app_api_credential', 'api_token', 'nullable', 'absent', '_app_api_credential']);
        $context = $factory->create($request, null, null);

        self::assertSame(['_app_api_credential', 'api_token', 'nullable', 'absent'], $factory->allowedAttributes());
        self::assertSame($request->attributes->get('_app_api_credential'), $context->allowlistedAttribute('_app_api_credential'), 'the same object');
        self::assertSame('REDACTED-TEST-x', $context->allowlistedAttribute('api_token'), 'the host allowlist wins over the secret-name rule');
        self::assertTrue($context->hasAllowlistedAttribute('nullable'));
        self::assertNull($context->allowlistedAttribute('nullable'));
        self::assertFalse($context->hasAllowlistedAttribute('absent'));
        self::assertNull($context->allowlistedAttribute('object'), 'not allowlisted');
        self::assertNull($context->allowlistedAttribute('_App_Api_Credential'), 'exact names');

        // the scalar view is the 1.19 one: an allowlisted name does not enter $attributes nor attribute()
        self::assertSame(['credential_id' => 'pt-7', 'nullable' => null], $context->attributes);
        self::assertNull($context->attribute('api_token'));
        self::assertNull($context->attribute('_app_api_credential'));
    }

    #[Test]
    public function allowlistedValuesNeverShowInADumpAndDoNotSurviveACopy(): void
    {
        $context = (new HttpRequestContextFactory([], HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS, ['_app_api_credential', 'api_token']))->create($this->request(), null, null);

        $cloner = new VarCloner();
        $dumper = new CliDumper();
        $dumper->setColors(false);
        $dumps = [
            'var_dumper' => (string) $dumper->dump($cloner->cloneVar($context), true),
            'var_export' => var_export($context, true),
            'array'      => print_r((array) $context, true),
            'print_r'    => print_r($context, true),
            'json'       => json_encode($context, JSON_THROW_ON_ERROR),
            'serialize'  => serialize($context),
        ];
        foreach ($dumps as $kind => $dump) {
            self::assertStringNotContainsString('REDACTED-TEST-', $dump, $kind);
            self::assertStringNotContainsString('personal_token', $dump, $kind);
        }

        $copy = unserialize($dumps['serialize']);
        self::assertInstanceOf(HttpRequestContext::class, $copy);
        self::assertNull($copy->allowlistedAttribute('_app_api_credential'));
        self::assertFalse($copy->hasAllowlistedAttribute('api_token'));
        $clone = clone $context;
        self::assertNull($clone->allowlistedAttribute('_app_api_credential'));
        self::assertNotNull($context->allowlistedAttribute('_app_api_credential'));
    }

    /** REQ-RUNTIME-002: one context per resolution, nothing kept from a previous request of the same worker. */
    #[Test]
    public function theResolutionHandsTheObjectToTheResolverAndKeepsNothingAcrossRequests(): void
    {
        $resolver   = new RecordingContextResolver(new HttpLogRequestContext('personal_token', 'pt-7'));
        $logger     = new CollectingLogger();
        $resolution = new HttpLogRequestContextResolution([$resolver], ['Authorization'], ['token'], $logger, null, null, null, [], HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS, ['_app_api_credential']);

        $first = $this->request();
        self::assertEquals(new HttpLogRequestContext('personal_token', 'pt-7'), $resolution->forRequest($first, 'api'));
        $second = Request::create('/api/tasks/43');
        $resolution->resolve($second, 'api');

        self::assertCount(2, $resolver->contexts);
        self::assertSame($first->attributes->get('_app_api_credential'), $resolver->contexts[0]->allowlistedAttribute('_app_api_credential'));
        self::assertNull($resolver->contexts[1]->allowlistedAttribute('_app_api_credential'), 'the next request does not see the previous object');
        self::assertNotSame($resolver->contexts[0], $resolver->contexts[1]);
        self::assertStringNotContainsString('REDACTED-TEST-', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(HttpLogRequestContext::class, $first->attributes->get(HttpLogRequestContextResolution::ATTRIBUTE), 'only the resolver answer is kept on the request');
    }
}
