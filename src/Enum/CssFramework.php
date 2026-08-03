<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Enum;

enum CssFramework: string
{
    case Bootstrap5 = 'bootstrap5';
    case Tailwind   = 'tailwind';
    case Foundation = 'foundation';
    case Custom     = 'custom';
}
