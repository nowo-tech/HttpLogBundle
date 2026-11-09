<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use LogicException;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Resolver of the named-channel tests: tenant from `X-Test-Org`, credential from the bearer token through a fixed map
 * of placeholder tokens to database ids. `leak` returns the bearer value itself (HL-1 §4.3 case 12), `throw` fails.
 */
final class TestRequestContextResolver implements HttpLogRequestContextResolverInterface
{
    /** @var list<string> */
    public array $channels = [];

    public function __construct(private readonly string $mode = 'ok')
    {
    }

    public function resolve(Request $request, ?TokenInterface $token, string $channel): HttpLogRequestContext
    {
        $this->channels[] = $channel;
        if ($this->mode === 'throw') {
            throw new LogicException('resolver failure');
        }

        $org    = $request->headers->get('X-Test-Org');
        $auth   = (string) $request->headers->get('Authorization');
        $bearer = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : null;
        if ($channel === 'webhooks') {
            return new HttpLogRequestContext('signature', 'stripe-endpoint-1', is_string($org) ? $org : null);
        }

        return new HttpLogRequestContext(
            $bearer === null ? null : 'personal_token',
            $bearer === null ? null : ($this->mode === 'leak' ? $bearer : 'pt-' . strlen($bearer)),
            is_string($org) ? $org : null,
            is_string($org) ? 'project-1' : null,
        );
    }
}
