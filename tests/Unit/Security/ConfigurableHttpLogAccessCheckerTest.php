<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Security;

use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ConfigurableHttpLogAccessCheckerTest extends TestCase
{
    public function testGrantsAccessWhenUserHasRequiredRole(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnMap([
            ['ROLE_ADMIN', false],
            ['ROLE_OPERATOR', true],
        ]);

        $checker = new ConfigurableHttpLogAccessChecker($authorizationChecker, ['ROLE_ADMIN', 'ROLE_OPERATOR']);

        self::assertTrue($checker->canAccessAdmin(null));
        self::assertTrue($checker->canExport(null));
        self::assertTrue($checker->canPurge(null));
    }

    public function testDeniesAccessWhenNoRoleMatches(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(false);

        $checker = new ConfigurableHttpLogAccessChecker($authorizationChecker, ['ROLE_ADMIN']);

        self::assertFalse($checker->canAccessAdmin(null));
        self::assertFalse($checker->canExport(null));
        self::assertFalse($checker->canPurge(null));
    }

    public function testEmptyRolesAllowEveryone(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::never())->method('isGranted');

        $checker = new ConfigurableHttpLogAccessChecker($authorizationChecker, []);

        self::assertTrue($checker->canAccessAdmin(null));
    }
}
