<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use RuntimeException;
use SensitiveParameter;

use function str_starts_with;

/**
 * Simulates a MySQL deadlock / lock wait timeout on the entry insert: the server rolls back the whole transaction
 * (savepoints included), then the statement fails.
 */
final class TransactionLostOnInsertMiddleware implements Middleware
{
    public const MESSAGE = 'Deadlock found when trying to get lock; the transaction was rolled back';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        if (str_starts_with($sql, 'INSERT INTO nowo_http_log_entry')) {
                            $this->exec('ROLLBACK');

                            throw new RuntimeException(TransactionLostOnInsertMiddleware::MESSAGE);
                        }

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }
}
