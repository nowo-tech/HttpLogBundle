<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Symfony\Component\HttpFoundation\Response;

final class UsageTestController
{
    public function hello(): Response
    {
        return new Response('hello', 200, ['Content-Type' => 'text/plain']);
    }
}
