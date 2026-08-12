<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

final class HttpLogDeleteType extends AbstractHttpLogActionType
{
    protected function csrfTokenId(): string
    {
        return 'http_log_delete';
    }
}
