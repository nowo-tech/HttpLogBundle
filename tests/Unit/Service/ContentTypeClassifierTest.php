<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use Nowo\HttpLogBundle\Enum\BodyContentType;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentTypeClassifierTest extends TestCase
{
    private ContentTypeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ContentTypeClassifier();
    }

    #[DataProvider('headerClassificationProvider')]
    public function testClassifiesFromContentTypeHeader(?string $header, BodyContentType $expected): void
    {
        self::assertSame($expected, $this->classifier->classify($header));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: BodyContentType}>
     */
    public static function headerClassificationProvider(): iterable
    {
        yield 'soap+xml' => ['application/soap+xml; charset=utf-8', BodyContentType::Soap];
        yield 'json' => ['application/json', BodyContentType::Json];
        yield 'problem+json' => ['application/problem+json', BodyContentType::Json];
        yield 'html' => ['text/html; charset=UTF-8', BodyContentType::Html];
        yield 'xml' => ['application/xml', BodyContentType::Xml];
        yield 'soap legacy' => ['application/soap', BodyContentType::Xml];
        yield 'plain text' => ['text/plain', BodyContentType::Text];
        yield 'octet stream' => ['application/octet-stream', BodyContentType::Binary];
        yield 'image' => ['image/png', BodyContentType::Binary];
        yield 'audio' => ['audio/mpeg', BodyContentType::Binary];
        yield 'video' => ['video/mp4', BodyContentType::Binary];
        yield 'unknown header' => ['application/vnd.custom', BodyContentType::Other];
        yield 'empty header' => ['', BodyContentType::Other];
        yield 'null header' => [null, BodyContentType::Other];
    }

    public function testSniffsSoapEnvelopeWhenHeaderMissing(): void
    {
        $body = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"></soap:Envelope>';

        self::assertSame(BodyContentType::Soap, $this->classifier->classify(null, $body));
    }

    public function testSniffsJsonWhenHeaderMissing(): void
    {
        self::assertSame(BodyContentType::Json, $this->classifier->classify(null, '  {"ok":true}'));
        self::assertSame(BodyContentType::Json, $this->classifier->classify(null, '[1,2]'));
    }

    public function testSniffsHtmlWhenHeaderMissing(): void
    {
        self::assertSame(BodyContentType::Html, $this->classifier->classify(null, '<!DOCTYPE html><html><body></body></html>'));
    }

    public function testSniffsXmlWhenHeaderMissing(): void
    {
        self::assertSame(BodyContentType::Xml, $this->classifier->classify(null, '<root/>'));
    }

    public function testHeaderWinsOverBodySniffing(): void
    {
        self::assertSame(
            BodyContentType::Json,
            $this->classifier->classify('application/json', '<html>not html</html>'),
        );
    }

    public function testEmptyBodyFallsBackToOther(): void
    {
        self::assertSame(BodyContentType::Other, $this->classifier->classify(null, ''));
        self::assertSame(BodyContentType::Other, $this->classifier->classify(null));
    }
}
