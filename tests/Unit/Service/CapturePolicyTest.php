<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use Nowo\HttpLogBundle\Enum\BodyContentType;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class CapturePolicyTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function createPolicy(array $config): CapturePolicy
    {
        return new CapturePolicy(array_merge([
            'request_body'          => false,
            'max_body_bytes'        => 10,
            'response_body_by_type' => [
                'html'   => false,
                'json'   => true,
                'soap'   => false,
                'xml'    => false,
                'text'   => false,
                'binary' => false,
                'other'  => false,
            ],
        ], $config));
    }

    public function testRequestBodyNotCapturedByDefault(): void
    {
        $policy = $this->createPolicy([]);

        self::assertFalse($policy->shouldCaptureRequestBody());
        self::assertSame(
            ['stored' => false, 'truncated' => false, 'body' => null],
            $policy->evaluateRequestBody('payload'),
        );
    }

    public function testCapturesAndTruncatesRequestBody(): void
    {
        $policy = $this->createPolicy(['request_body' => true, 'max_body_bytes' => 5]);
        $result = $policy->evaluateRequestBody('123456789');

        self::assertTrue($result['stored']);
        self::assertTrue($result['truncated']);
        self::assertSame('12345', $result['body']);
    }

    public function testMaxBodyBytesZeroStoresEmptyString(): void
    {
        $policy = $this->createPolicy(['request_body' => true, 'max_body_bytes' => 0]);
        $result = $policy->evaluateRequestBody('anything');

        self::assertTrue($result['stored']);
        self::assertFalse($result['truncated']);
        self::assertSame('', $result['body']);
    }

    public function testResponseBodyCapturedOnlyForEnabledTypes(): void
    {
        $policy = $this->createPolicy([]);

        self::assertSame(
            ['stored' => false, 'truncated' => false, 'body' => null],
            $policy->evaluateResponseBody('{"a":1}', BodyContentType::Html),
        );

        $jsonResult = $policy->evaluateResponseBody('{"a":1}', BodyContentType::Json);
        self::assertTrue($jsonResult['stored']);
        self::assertSame('{"a":1}', $jsonResult['body']);
    }

    public function testEmptyResponseBodyIsNotStored(): void
    {
        $policy = $this->createPolicy([]);

        self::assertSame(
            ['stored' => false, 'truncated' => false, 'body' => null],
            $policy->evaluateResponseBody('', BodyContentType::Json),
        );
        self::assertSame(
            ['stored' => false, 'truncated' => false, 'body' => null],
            $policy->evaluateResponseBody(null, BodyContentType::Json),
        );
    }

    public function testClassifyResponseContentType(): void
    {
        $policy   = $this->createPolicy([]);
        $response = new Response('ok', 200, ['Content-Type' => 'application/json']);

        self::assertSame('application/json', $policy->classifyResponseContentType($response));
    }
}
