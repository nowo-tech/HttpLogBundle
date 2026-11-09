<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Controller of the named-channel kernel tests (HL-1 S4). */
final readonly class ApiTestController
{
    public function __construct(private ?HttpClientInterface $http = null)
    {
    }

    public function task(string $id): JsonResponse
    {
        return match ($id) {
            'fail'  => throw new RuntimeException('controller failure'),
            '404'   => new JsonResponse(['error' => 'not found'], 404),
            default => new JsonResponse(['id' => $id]),
        };
    }

    public function share(): JsonResponse
    {
        return new JsonResponse(['denied' => true], 403);
    }

    /** Makes one outbound call while the request is served. */
    public function sync(): Response
    {
        $this->http?->request('GET', 'https://api.clickup.com/api/v2/task/1')->getStatusCode();

        return new Response('synced');
    }

    public function ok(): Response
    {
        return new Response('ok');
    }
}
