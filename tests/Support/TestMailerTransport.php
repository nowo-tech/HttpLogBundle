<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

/**
 * Mailer transport of the tests: its name is the given DSN-like string (credentials included, as a careless custom
 * transport could print them) and each send succeeds or throws the next queued error.
 */
final class TestMailerTransport extends AbstractTransport
{
    /** @param list<Throwable|null> $outcomes */
    public function __construct(private readonly string $name, private array $outcomes = [], ?EventDispatcherInterface $dispatcher = null)
    {
        parent::__construct($dispatcher);
    }

    protected function doSend(SentMessage $message): void
    {
        $error = array_shift($this->outcomes);
        if ($error instanceof Throwable) {
            throw $error;
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
