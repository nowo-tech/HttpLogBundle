<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see HttpLogPersonalDataSubjectResolverInterface}: the original identifier, else the user's current
 * `getUserIdentifier()`. A value the log column could not hold (empty, NUL byte, too long) yields no subject.
 */
final readonly class UserIdentifierSubjectResolver implements HttpLogPersonalDataSubjectResolverInterface
{
    public function subjectsFor(object $user, ?string $originalIdentifier): array
    {
        $identifier = $originalIdentifier ?? ($user instanceof UserInterface ? $user->getUserIdentifier() : null);
        if ($identifier === null) {
            return [];
        }

        try {
            return [HttpLogPersonalDataSubject::fromUserIdentifier($identifier)];
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
