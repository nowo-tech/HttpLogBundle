<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\BodyContentType;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
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

use function array_column;
use function array_key_exists;
use function array_map;
use function str_repeat;

use const DATE_ATOM;

final class HttpLogRecorderTest extends TestCase
{
    private ?UsageTestDatabase $database = null;

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
    public function recordKeepsTheHostPortOfNonDefaultPorts(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);

        $recorder = $this->createRecorder($this->database()->entityManager, $messageBus, async: false);
        $recorder->record(Request::create('http://127.0.0.1:8000/api/items'), new Response('ok'), 1.0, 'req-port');
        $recorder->record(Request::create('https://example.test/api/items'), new Response('ok'), 1.0, 'req-default');

        self::assertSame(['127.0.0.1:8000', 'example.test'], array_column($this->database()->rows(), 'host'));
    }

    #[Test]
    public function asyncFalseWritesTheRowWithoutTouchingTheUnitOfWork(): void
    {
        $entityManager = $this->database()->entityManager;

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $recorder = $this->createRecorder($entityManager, $messageBus, async: false);
        $recorder->record(
            Request::create('/api/items', 'GET'),
            new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
            15.0,
            'req-sync',
        );

        self::assertSame(['req-sync'], array_column($this->database()->rows(), 'request_id'));
        self::assertSame(0, $entityManager->getUnitOfWork()->size());
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
    public function persistCaptureInsertsEveryMappedField(): void
    {
        $recorder = $this->createRecorder(
            $this->database()->entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
        );

        $recorder->persistCapture($this->createCapture());

        $entry = $this->lastEntry();
        self::assertInstanceOf(HttpLogEntry::class, $entry);
        self::assertSame('req-1', $entry->getRequestId());
        self::assertSame('POST', $entry->getMethod());
        self::assertSame('https', $entry->getScheme());
        self::assertSame('example.test', $entry->getHost());
        self::assertSame('api_create', $entry->getRouteName());
        self::assertSame([], $entry->getQueryParams());
        self::assertSame(201, $entry->getStatusCode());
        self::assertSame('admin', $entry->getUserIdentifier());
        self::assertNull($entry->getRequestBody());
        self::assertSame('{"created":true}', $entry->getResponseBody());
        self::assertTrue($entry->isResponseBodyStored());
        self::assertFalse($entry->isRequestBodyTruncated());
        self::assertSame(20.0, $entry->getDurationMs());
        self::assertSame('2026-08-03T10:00:00+00:00', $entry->getCreatedAt()->format(DATE_ATOM));
    }

    #[Test]
    public function persistCaptureUsesAPreInsertIdGeneratorChosenByTheHost(): void
    {
        $entityManager = $this->database()->entityManager;
        $entityManager->getClassMetadata(HttpLogEntry::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return 4242;
            }
        });

        $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false)
            ->persistCapture($this->createCapture());

        self::assertSame([4242], array_map(intval(...), array_column($this->database()->rows(), 'id')));
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
        $entityManager = $this->database()->entityManager;

        $policy = $this->createMock(CapturePolicy::class);
        $policy->method('shouldCaptureRequestBody')->willReturn(true);
        $policy->method('shouldCaptureResponseBody')->with(BodyContentType::Other)->willReturn(false);
        $policy->method('evaluateRequestBody')->with('{"secret":"[REDACTED]"}')->willReturn([
            'stored'    => true,
            'truncated' => false,
            'body'      => '{"secret":"[REDACTED]"}',
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
            ->method('redactBody')
            ->with('{"secret":"value"}', 'application/x-www-form-urlencoded')
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

        $capturedEntry = $this->lastEntry();
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
    public function persistCaptureUsesTheManagerOfTheEntryFromTheRegistry(): void
    {
        $injected = $this->createMock(EntityManagerInterface::class);
        $injected->expects(self::never())->method('getConnection');

        $recorder = $this->createRecorder($injected, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $this->database());
        $recorder->persistCapture($this->createCapture());

        self::assertCount(1, $this->database()->rows());
    }

    #[Test]
    public function persistCaptureFallsBackToInjectedManagerWhenRegistryHasNoManagerForEntity(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $registry->expects(self::never())->method('resetManager');

        $recorder = $this->createRecorder($this->database()->entityManager, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());

        self::assertCount(1, $this->database()->rows());
    }

    #[Test]
    public function aClosedManagerStillWritesAndIsNeverReset(): void
    {
        $entityManager = $this->database()->entityManager;
        $entityManager->close();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $registry->expects(self::never())->method('resetManager');

        $recorder = $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false, managerRegistry: $registry);
        $recorder->persistCapture($this->createCapture());

        self::assertCount(1, $this->database()->rows());
    }

    #[Test]
    public function failedInsertIsRethrownAndLeavesTheManagerOpen(): void
    {
        $entityManager = $this->database()->entityManager;
        $this->database()->connection()->executeStatement('DROP TABLE ' . $this->database()->table());

        $recorder = $this->createRecorder($entityManager, $this->createMock(MessageBusInterface::class), async: false);

        try {
            $recorder->persistCapture($this->createCapture());
            self::fail('The insert must fail without its table.');
        } catch (DbalException) {
            self::assertTrue($entityManager->isOpen());
        }
    }

    #[Test]
    public function failedInsertDuringRecordIsLogged(): void
    {
        $this->database()->connection()->executeStatement('DROP TABLE ' . $this->database()->table());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to record HTTP log entry.');

        $this->createRecorder($this->database()->entityManager, $this->createMock(MessageBusInterface::class), async: false, logger: $logger)
            ->record(Request::create('/broken'), new Response('ok'), 1.0, 'req-1');
    }

    #[Test]
    public function requestJsonBodyIsRedactedBeforeTruncation(): void
    {
        $entityManager = $this->database()->entityManager;

        $recorder = $this->createRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
            redactor: new HttpLogRedactor([], ['password'], ['*.password']),
            policy: new CapturePolicy(['request_body' => true, 'max_body_bytes' => 40] + $this->captureConfig),
            captureConfig: ['request_body' => true, 'max_body_bytes' => 40] + $this->captureConfig,
        );

        // Truncated to 40 bytes the document is invalid JSON; it must still not leak the password.
        $body    = '{"user":{"password":"hunter2-secret"},"padding":"' . str_repeat('x', 200) . '"}';
        $request = Request::create('/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        $recorder->record($request, new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']), 1.0, null);

        $capturedEntry = $this->lastEntry();
        self::assertInstanceOf(HttpLogEntry::class, $capturedEntry);
        self::assertTrue($capturedEntry->isRequestBodyTruncated());
        self::assertIsString($capturedEntry->getRequestBody());
        self::assertStringNotContainsString('hunter2-secret', $capturedEntry->getRequestBody());
        self::assertStringStartsWith('{"user":{"password":"[REDACTED]"}', $capturedEntry->getRequestBody());
    }

    #[Test]
    public function formLoginBodyIsRedactedWithDefaultKeys(): void
    {
        $entityManager = $this->database()->entityManager;

        $recorder = $this->createRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
            redactor: new HttpLogRedactor(
                [],
                ['password', '_password', 'token', '_token', '_csrf_token', 'api_key'],
                ['*.password', '*._password', '*.token', '*._token', '*._csrf_token'],
            ),
            policy: new CapturePolicy(['request_body' => true] + $this->captureConfig),
            captureConfig: ['request_body' => true] + $this->captureConfig,
        );

        $request = Request::create(
            '/login',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            '_username=ada&_password=hunter2&_csrf_token=abc123',
        );

        $recorder->record($request, new Response('', 302), 1.0, null);

        $capturedEntry = $this->lastEntry();
        self::assertInstanceOf(HttpLogEntry::class, $capturedEntry);
        self::assertSame(
            '_username=ada&_password=%5BREDACTED%5D&_csrf_token=%5BREDACTED%5D',
            $capturedEntry->getRequestBody(),
        );
    }

    #[Test]
    public function responseBodyIsRedactedBeforeTruncation(): void
    {
        $entityManager = $this->database()->entityManager;

        $recorder = $this->createRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            async: false,
            redactor: new HttpLogRedactor([], [], ['*.token']),
            policy: new CapturePolicy(['max_body_bytes' => 30] + $this->captureConfig),
            captureConfig: ['max_body_bytes' => 30] + $this->captureConfig,
        );

        $body = '{"token":"jwt-secret-value","items":[' . str_repeat('1,', 100) . '1]}';
        $recorder->record(
            Request::create('/api/token'),
            new Response($body, 200, ['Content-Type' => 'application/json']),
            1.0,
            null,
        );

        $capturedEntry = $this->lastEntry();
        self::assertInstanceOf(HttpLogEntry::class, $capturedEntry);
        self::assertTrue($capturedEntry->isResponseBodyTruncated());
        self::assertStringNotContainsString('jwt-secret-value', (string) $capturedEntry->getResponseBody());
    }

    /** In-memory SQLite database of the entry (one per test). */
    private function database(): UsageTestDatabase
    {
        return $this->database ??= new UsageTestDatabase(usageColumns: false);
    }

    /** The last written entry, hydrated from its row. */
    private function lastEntry(): ?HttpLogEntry
    {
        $this->database()->entityManager->clear();

        return $this->database()->repository()->findOneBy([], ['id' => 'DESC']);
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
        ?HttpLogBuffer $buffer = null,
        ?HttpLogErasedSubjects $erased = null,
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
            $buffer,
            $erased,
        );
    }

    #[Test]
    public function aRequestOfAValueErasedInTheSameRequestIsNotRecorded(): void
    {
        $erased   = new HttpLogErasedSubjects();
        $tokens   = new TokenStorage();
        $recorder = $this->createRecorder($this->database()->entityManager, $this->createMock(MessageBusInterface::class), false, captureConfig: ['user' => true] + $this->captureConfig, tokenStorage: $tokens, erased: $erased);
        $tokens->setToken($this->createUserToken('ana'));

        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        $recorder->record($this->createFirewalledRequest('/account/delete'), new Response('ok'), 1.0, 'req-erase');
        self::assertSame([], $this->database()->rows(), 'the erasing request is not written at kernel.terminate (LY-L2)');

        $erased->reset();
        $erased->remember(HttpLogPersonalDataSubject::fromClientIp('127.0.0.1'));
        $tokens->setToken(null);
        $recorder->record(Request::create('/x'), new Response('ok'), 1.0, 'req-ip');
        self::assertSame([], $this->database()->rows(), 'matched by client IP');

        $erased->reset();
        $erased->dropCurrentRequest();
        $tokens->setToken($this->createUserToken('anonymised-1'));
        $recorder->record($this->createFirewalledRequest('/account/delete'), new Response('ok'), 1.0, 'req-self');
        self::assertSame([], $this->database()->rows(), 'self-service: the identifier changed, the request is still dropped');

        $erased->reset();
        $tokens->setToken($this->createUserToken('ana'));
        $recorder->record($this->createFirewalledRequest('/hello'), new Response('ok'), 1.0, 'req-next');
        self::assertSame(['ana'], array_column($this->database()->rows(), 'user_identifier'), 'the next request is recorded');
    }

    #[Test]
    public function theWebDispatchSuspendsOutboundCapture(): void
    {
        $buffer = new HttpLogBuffer($this->createMock(UsageCaptureSink::class));
        $seen   = null;
        $bus    = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($buffer, &$seen): Envelope {
            $seen = $buffer->isSuspended(); // a transport using a decorated client is not captured (IX-L3)

            return new Envelope($message);
        });

        $this->createRecorder($this->createMock(EntityManagerInterface::class), $bus, true, buffer: $buffer)
            ->record(Request::create('/x'), new Response('ok'), 1.0, 'req-1');

        self::assertTrue($seen);
        self::assertFalse($buffer->isSuspended());
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
        $redactor->method('redactBody')->willReturnArgument(0);

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
