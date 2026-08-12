<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

final class HttpLogPurgeType extends AbstractHttpLogActionType
{
    protected function csrfTokenId(): string
    {
        return 'http_log_purge';
    }
}
