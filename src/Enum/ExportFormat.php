<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Enum;

enum ExportFormat: string
{
    case Csv  = 'csv';
    case Json = 'json';
}
