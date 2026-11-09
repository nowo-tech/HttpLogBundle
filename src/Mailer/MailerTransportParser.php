<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Mailer;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;

use function preg_match;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Scheme and host of a Mailer transport name (`(string) $transport`: `smtp://smtp.example:587`, `resend+api://…`),
 * HL-1 §12. User info, path and query are dropped; a DSN's credentials never reach a row.
 */
final class MailerTransportParser
{
    /**
     * @return array{scheme: string|null, host: string|null}
     */
    public static function parse(string $transport): array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):\/\/([^\/?#\s()]*)/', $transport, $match) !== 1) {
            return ['scheme' => null, 'host' => null];
        }

        $authority = $match[2];
        $at        = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        return [
            'scheme' => UsageFieldGuard::scheme($match[1]),
            'host'   => UsageFieldGuard::host(strtolower($authority)),
        ];
    }
}
