<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\BodyContentType;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;

final class HttpLogRecorderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $captureConfig = [
        'request_headers'       => true,
        'request_body'          => false,
        'response_headers'      => true,
        'client_ip'             => true,
        'user'                  => false,
        'max_body_bytes'        => 65536,
        'response_body_by_type' => [
            'html' => false, 'json' => true, 'soap' => false, 'xml' => false,
            'text' => false, 'binary' => false, 'other' => false,
        ],
    ];

    #[Test]
    public function asyncTrueDispatchesMessageWithoutPersist(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PersistHttpLogMessage::class))
            ->willReturn(new Envelope(new stdClass()));

        $recorder = $this->createRecorder($entityManager, $messageBus, async: true);
        $recorder->record(
            Request::create('/api/items', 'GET'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            10.0,
            'req-async',
        );
    }

    #[Test]
    public function asyncFalsePersistsViaEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(HttpLogEntry::class));
        $entityManager->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $recorder = $this->createRecorder($entityManager, $messageBus, async: false);
        $recorder->record(
            Request::create('/api/items', 'GET'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            15.0,
            'req-sync',
        );
    }

    #[Test]
    public function exceptionDuringRecordLogsError(): void
    {
        $redactor = $this->createMock(HttpLogRedactor::class);
        $redactor->method('redactHeaders')->willThrowException(new RuntimeException('redaction failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Failed to record HTTP log entry.',
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['path'])
                    && $context['path'] === '/broken'),
            );

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            async: true,
            redactor: $redactor,
            logger: $logger,
        );

        $recorder->record(
            Request::create('/broken'),
            new Response('ok'),
            1.0,
            null,
        );
    }

    #[Test]
    public function persistCaptureDelegatesToEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(HttpLogEntry::class));
        $entityManager->expects(self::once())->method('flush');

        $recorder = $this->createRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
        );

        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function createEntryFromCaptureMapsAllFields(): void
    {
        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            async: true,
        );
        $capture = $this->createCapture();
        $entry   = $recorder->createEntryFromCapture($capture);

        self::assertSame('req-1', $entry->getRequestId());
        self::assertSame('POST', $entry->getMethod());
        self::assertSame('/api/create', $entry->getPath());
        self::assertSame(201, $entry->getStatusCode());
        self::assertTrue($entry->isResponseBodyStored());
    }

    #[Test]
    public function recordWithCaptureFlagsDisabledStoresNullSensitiveFields(): void
    {
        $capturedEntry = null;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (HttpLogEntry $entry) use (&$capturedEntry): bool {
                $capturedEntry = $entry;

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $policy = $this->createMock(CapturePolicy::class);
        $policy->method('evaluateRequestBody')->willReturn([
            'stored'    => true,
            'truncated' => false,
            'body'      => '{"secret":"value"}',
        ]);
        $policy->method('evaluateResponseBody')->with(null, BodyContentType::Other)->willReturn([
            'stored'    => false,
            'truncated' => false,
            'body'      => null,
        ]);

        $classifier = $this->createMock(ContentTypeClassifier::class);
        $classifier->expects(self::once())
            ->method('classify')
            ->with('text/plain', null)
            ->willReturn(BodyContentType::Other);

        $redactor = $this->createMock(HttpLogRedactor::class);
        $redactor->expects(self::never())->method('redactHeaders');
        $redactor->expects(self::once())->method('redactQueryParams')->willReturnArgument(0);
        $redactor->expects(self::once())
            ->method('redactJsonBody')
            ->with('{"secret":"value"}')
            ->willReturn('{"secret":"[REDACTED]"}');

        $recorder = $this->createRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
            redactor: $redactor,
            policy: $policy,
            classifier: $classifier,
            captureConfig: [
                'request_headers'  => false,
                'response_headers' => false,
                'client_ip'        => false,
                'user'             => true,
            ] + $this->captureConfig,
        );

        $request  = Request::create('/stream', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '{"secret":"value"}');
        $response = new StreamedResponse(static function (): void {
        }, 200, ['Content-Type' => 'text/plain']);

        $recorder->record($request, $response, 8.5, 'stream-1');

        self::assertInstanceOf(HttpLogEntry::class, $capturedEntry);
        self::assertNull($capturedEntry->getRequestHeaders());
        self::assertNull($capturedEntry->getResponseHeaders());
        self::assertNull($capturedEntry->getClientIp());
        self::assertNull($capturedEntry->getUserIdentifier());
        self::assertSame('{"secret":"[REDACTED]"}', $capturedEntry->getRequestBody());
        self::assertNull($capturedEntry->getResponseBody());
        self::assertFalse($capturedEntry->isResponseBodyStored());
    }

    #[Test]
    public function recordResolvesStringableUserIdentifierFromSecurityToken(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'string-user';
            }

            public function __toString(): string
            {
                return 'string-user';
            }
        });

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => ($message->payload['userIdentifier'] ?? null) === 'string-user'))
            ->willReturn(new Envelope(new stdClass()));

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
        );

        $recorder->record(
            $this->createFirewalledRequest('/secure'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            5.0,
            'user-string',
        );
    }

    #[Test]
    public function recordResolvesObjectUserIdentifierFromSecurityToken(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'object-user';
            }
        });

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => ($message->payload['userIdentifier'] ?? null) === 'object-user'))
            ->willReturn(new Envelope(new stdClass()));

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
        );

        $recorder->record(
            $this->createFirewalledRequest('/secure-object'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            5.0,
            'user-object',
        );
    }

    #[Test]
    public function recordLeavesUserIdentifierNullWhenTokenUserIsNull(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => ($message->payload['userIdentifier'] ?? null) === null))
            ->willReturn(new Envelope(new stdClass()));

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
        );

        $recorder->record(
            $this->createFirewalledRequest('/secure-null-user'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            5.0,
            'user-null',
        );
    }

    #[Test]
    public function recordLeavesUserIdentifierNullWhenTokenIsMissing(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => array_key_exists('userIdentifier', $message->payload)
                && $message->payload['userIdentifier'] === null))
            ->willReturn(new Envelope(new stdClass()));

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
        );

        $recorder->record(
            $this->createFirewalledRequest('/missing-token'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            5.0,
            'missing-token',
        );
    }

    #[Test]
    public function staleTokenFromPreviousRequestIsNotRecordedWhenNoFirewallHandlesTheNextRequest(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($this->createUserToken('alice'));

        $payloads   = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (PersistHttpLogMessage $message) use (&$payloads): Envelope {
                $payloads[] = $message->payload;

                return new Envelope($message);
            });

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
        );

        $recorder->record($this->createFirewalledRequest('/account'), new Response('ok'), 1.0, 'req-1');
        $recorder->record(Request::create('/public'), new Response('ok'), 1.0, 'req-2');

        self::assertSame('alice', $payloads[0]['userIdentifier']);
        self::assertNull($payloads[1]['userIdentifier']);
    }

    /**
     * @return iterable<string, array{?FirewallConfig, ?string}>
     */
    public static function firewallConfigProvider(): iterable
    {
        yield 'secured firewall' => [new FirewallConfig('main', 'security.user_checker'), 'bob'];
        yield 'security: false firewall' => [new FirewallConfig('dev', 'security.user_checker', null, false), null];
        yield 'no firewall' => [null, null];
    }

    #[Test]
    #[DataProvider('firewallConfigProvider')]
    public function userIdentifierIsOnlyTrustedWhenCurrentRequestPassedASecuredFirewall(?FirewallConfig $config, ?string $expected): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($this->createUserToken('bob'));

        $request  = Request::create('/any');
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('getFirewallConfig')->with($request)->willReturn($config);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => $message->payload['userIdentifier'] === $expected))
            ->willReturnCallback(static fn (PersistHttpLogMessage $message): Envelope => new Envelope($message));

        $recorder = $this->createRecorder(
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            async: true,
            captureConfig: ['user' => true] + $this->captureConfig,
            tokenStorage: $tokenStorage,
            security: $security,
        );

        $recorder->record($request, new Response('ok'), 1.0, 'req-fw');
    }

    #[Test]
    public function persistCaptureDetachesEntryAfterFlush(): void
    {
        $persisted     = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (HttpLogEntry $entry) use (&$persisted): void {
                $persisted = $entry;
            });
        $entityManager->expects(self::once())->method('flush');
        $entityManager->method('contains')->willReturnCallback(static function (object $entry) use (&$persisted): bool {
            return $entry === $persisted;
        });
        $entityManager->expects(self::once())
            ->method('detach')
            ->with(self::isInstanceOf(HttpLogEntry::class));

        $recorder = $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false);
        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function persistCaptureUsesManagerFromRegistryAndResetsItWhenClosed(): void
    {
        $injected = $this->createMock(EntityManagerInterface::class);
        $injected->expects(self::never())->method('persist');

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::never())->method('persist');

        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('isOpen')->willReturn(true);
        $fresh->expects(self::once())->method('persist');
        $fresh->expects(self::once())->method('flush');

        $other    = $this->createMock(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(HttpLogEntry::class)->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager', 'logs' => 'doctrine.orm.logs_entity_manager']);
        $registry->method('getManager')->willReturnMap([['other', $other], ['logs', $closed]]);
        $registry->expects(self::once())->method('resetManager')->with('logs')->willReturn($fresh);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::anything(), ['manager' => 'logs']);

        $recorder = $this->createRecorder($injected, $this->createMock(MessageBusInterface::class), async: false, logger: $logger, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function persistCaptureFallsBackToInjectedManagerWhenRegistryHasNoManagerForEntity(): void
    {
        $injected = $this->createMock(EntityManagerInterface::class);
        $injected->method('isOpen')->willReturn(true);
        $injected->expects(self::once())->method('persist');
        $injected->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $registry->expects(self::never())->method('resetManager');

        $recorder = $this->createRecorder($injected, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function failedFlushResetsClosedManagerSoTheNextRequestCanLogAgain(): void
    {
        $open          = true;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$open): never {
            $open = false;

            throw new RuntimeException('insert failed');
        });

        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('isOpen')->willReturn(true);
        $fresh->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static function () use (&$open, $entityManager, $fresh): EntityManagerInterface {
            return $open ? $entityManager : $fresh;
        });
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->with('default')->willReturn($entityManager);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($fresh);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to record HTTP log entry.');

        $recorder = $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false, logger: $logger, managerRegistry: $registry);

        $recorder->record(Request::create('/first'), new Response('ok'), 1.0, 'req-1');
        $recorder->record(Request::create('/second'), new Response('ok'), 1.0, 'req-2');
    }

    #[Test]
    public function closedManagerIsKeptWhenRegistryCannotResetIt(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::once())->method('persist');
        $closed->expects(self::never())->method('detach');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager', 'odm' => 'doctrine_mongodb.odm.default_document_manager']);
        $registry->method('getManager')->willReturnMap([
            ['default', $this->createMock(EntityManagerInterface::class)],
            ['odm', $closed],
        ]);
        $registry->method('resetManager')->willReturn($this->createMock(ObjectManager::class));

        $recorder = $this->createRecorder($closed, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function closedManagerUnknownToRegistryIsNotReset(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::once())->method('persist');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->expects(self::never())->method('resetManager');

        $recorder = $this->createRecorder($closed, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());
    }

    #[Test]
    public function failedFlushWithoutRegistryIsRethrown(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $entityManager->method('flush')->willThrowException(new RuntimeException('insert failed'));

        $recorder = $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insert failed');

        $recorder->persistCapture($this->createCapture());
    }

    private function createUserToken(string $identifier): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($identifier, null), 'main');
    }

    private function createRecorder(
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
        bool $async,
        ?HttpLogRedactor $redactor = null,
        ?LoggerInterface $logger = null,
        ?CapturePolicy $policy = null,
        ?ContentTypeClassifier $classifier = null,
        ?array $captureConfig = null,
        ?TokenStorageInterface $tokenStorage = null,
        ?ManagerRegistry $managerRegistry = null,
        ?Security $security = null,
    ): HttpLogRecorder {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-03T10:00:00+00:00'));

        if (!$policy instanceof CapturePolicy) {
            $policy = $this->createMock(CapturePolicy::class);
            $policy->method('evaluateRequestBody')->willReturn(['stored' => false, 'truncated' => false, 'body' => null]);
            $policy->method('evaluateResponseBody')->willReturn(['stored' => true, 'truncated' => false, 'body' => '{"ok":true}']);
        }

        if (!$classifier instanceof ContentTypeClassifier) {
            $classifier = $this->createMock(ContentTypeClassifier::class);
            $classifier->method('classify')->willReturn(BodyContentType::Json);
        }

        $redactor ??= $this->createConfiguredRedactor();
        $captureConfig ??= $this->captureConfig;

        return new HttpLogRecorder(
            $entityManager,
            $messageBus,
            $clock,
            $logger ?? $this->createMock(LoggerInterface::class),
            $redactor,
            $policy,
            $classifier,
            $async,
            $captureConfig,
            $tokenStorage,
            $managerRegistry,
            $security,
        );
    }

    private function createFirewalledRequest(string $path): Request
    {
        $request = Request::create($path, 'GET');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');

        return $request;
    }

    private function createConfiguredRedactor(): HttpLogRedactor
    {
        $redactor = $this->createMock(HttpLogRedactor::class);
        $redactor->method('redactHeaders')->willReturnArgument(0);
        $redactor->method('redactQueryParams')->willReturnArgument(0);
        $redactor->method('redactJsonBody')->willReturnArgument(0);

        return $redactor;
    }

    private function createCapture(): HttpLogCapture
    {
        return new HttpLogCapture(
            requestId: 'req-1',
            method: 'POST',
            scheme: 'https',
            host: 'example.test',
            path: '/api/create',
            routeName: 'api_create',
            queryParams: [],
            statusCode: 201,
            clientIp: '10.0.0.1',
            contentType: 'application/json',
            bodyContentType: 'json',
            durationMs: 20.0,
            userIdentifier: 'admin',
            requestHeaders: [],
            responseHeaders: [],
            requestBody: null,
            responseBody: '{"created":true}',
            requestBodyTruncated: false,
            responseBodyTruncated: false,
            responseBodyStored: true,
            createdAt: new DateTimeImmutable('2026-08-03T10:00:00+00:00'),
        );
    }
}
