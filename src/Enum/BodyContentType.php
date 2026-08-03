<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Enum;

enum BodyContentType: string
{
    case Html   = 'html';
    case Json   = 'json';
    case Soap   = 'soap';
    case Xml    = 'xml';
    case Text   = 'text';
    case Binary = 'binary';
    case Other  = 'other';
}
