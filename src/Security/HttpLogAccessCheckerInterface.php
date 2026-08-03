<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Security;

/**
 * Access control for HTTP log admin UI and destructive actions.
 */
interface HttpLogAccessCheckerInterface
{
    public function canAccessAdmin(?object $user): bool;

    public function canExport(?object $user): bool;

    public function canPurge(?object $user): bool;
}
