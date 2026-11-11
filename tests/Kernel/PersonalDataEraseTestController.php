<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasureInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Erases the HTTP log of the caller's own client IP inside the request (LY-L2 kernel test; registered by that test). */
final readonly class PersonalDataEraseTestController
{
    public function __construct(private HttpLogPersonalDataErasureInterface $erasure)
    {
    }

    public function eraseMyIp(Request $request): Response
    {
        $result = $this->erasure->erase(HttpLogPersonalDataSubject::fromClientIp((string) $request->getClientIp()));

        return new Response((string) $result->entries, 200, ['Content-Type' => 'text/plain']);
    }
}
