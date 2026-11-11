<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface;

use function is_string;
use function str_starts_with;

/**
 * A DDD host's resolver: implements ONLY the framework-free port (no `Request`, no `TokenInterface`). Tenant from the
 * `X-Test-Org` header, credential id from the route parameter, both through the value object.
 */
final class DddHostContextResolver implements HttpRequestContextResolverInterface
{
    /** @var list<array{channel: string, route: string|null, authorization: string|null, org: string|null}> */
    public array $seen = [];

    public function resolveContext(HttpRequestContext $request, string $channel): ?HttpLogRequestContext
    {
        $org          = $request->header('X-Test-Org');
        $this->seen[] = ['channel' => $channel, 'route' => $request->routeName, 'authorization' => $request->header('Authorization'), 'org' => $org];
        if (!is_string($org) || !is_string($request->routeName) || !str_starts_with($request->routeName, 'api_')) {
            return null;
        }

        $id = $request->routeParam('id');

        return new HttpLogRequestContext('personal_token', is_string($id) ? 'pt-' . $id : null, $org, 'project-' . $org);
    }
}
