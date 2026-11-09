<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;
use function str_contains;

/** `framework.http_client.mock_response_factory` of the usage kernel: 500 then 200 for ClickUp, 200 otherwise. */
final class UsageMockResponses
{
    /** @var list<int> */
    private array $clickup = [500, 200];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (str_contains($url, 'clickup')) {
            return new MockResponse('{}', ['http_code' => array_shift($this->clickup) ?? 200]);
        }

        return new MockResponse('ok');
    }
}
