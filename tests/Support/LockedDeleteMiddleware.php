<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

use function str_starts_with;

/**
 * Lets the first `$allowed` DELETE statements through, then fails every later one as SQLite's "database is locked",
 * which DBAL converts to a LockWaitTimeoutException (a lock conflict of a purge batch, audit UV-2). With
 * `$lockSelects`, the prepared SELECT that follows the allowed deletes fails instead (the next batch's read, audit VB-3).
 */
final class LockedDeleteMiddleware implements Middleware
{
    public int $deletes = 0;

    public function __construct(
        public int $allowed,
        public bool $lockSelects = false,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $middleware = $this;

        return new class($driver, $middleware) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly LockedDeleteMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->middleware) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly LockedDeleteMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $locked = str_starts_with($sql, 'DELETE')
                            ? ++$this->middleware->deletes > $this->middleware->allowed
                            : $this->middleware->lockSelects && str_starts_with($sql, 'SELECT') && $this->middleware->deletes >= $this->middleware->allowed;
                        if ($locked) {
                            throw new class('database is locked', 'HY000', 5) extends AbstractException {
                            };
                        }

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }
}
