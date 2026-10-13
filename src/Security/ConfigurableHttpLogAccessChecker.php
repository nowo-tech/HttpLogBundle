<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_http_log.security.access_roles config.
 */
final readonly class ConfigurableHttpLogAccessChecker implements HttpLogAccessCheckerInterface
{
    /** @param list<string> $accessRoles */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canAccessAdmin(?object $user): bool
    {
        return $this->hasAnyRole();
    }

    public function canExport(?object $user): bool
    {
        return $this->hasAnyRole();
    }

    public function canPurge(?object $user): bool
    {
        return $this->hasAnyRole();
    }

    /**
     * Fails closed: an empty access_roles list grants nobody (use security.allow_unauthenticated
     * or a custom access checker to open the admin UI deliberately).
     */
    private function hasAnyRole(): bool
    {
        if ($this->accessRoles === []) {
            return false;
        }

        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
