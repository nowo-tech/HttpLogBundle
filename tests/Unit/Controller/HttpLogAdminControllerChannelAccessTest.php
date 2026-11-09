<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Security\HttpLogChannelAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\ShowPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function array_column;
use function array_map;
use function explode;
use function in_array;
use function trim;

/**
 * HL-1 §11.2: with outbound / mailer mapped, a checker that implements HttpLogChannelAccessCheckerInterface hides the
 * channels it denies from the list, the entry page (same 404 as a missing entry), the export and the purge.
 */
final class HttpLogAdminControllerChannelAccessTest extends TestCase
{
    private UsageTestDatabase $db;

    /** @var array<string, int> channel => id */
    private array $ids = [];

    /** @var array<string, mixed> */
    private array $rendered = [];

    /** @var array<string, mixed> */
    private array $filterOptions = [];

    protected function setUp(): void
    {
        $this->db = new UsageTestDatabase();
        $old      = new DateTimeImmutable('2026-09-01T10:00:00+00:00');
        $web      = (new HttpLogEntry())->setPath('/web')->setCreatedAt($old);
        $this->db->entityManager->persist($web);
        $this->db->entityManager->flush();
        $this->ids['web'] = (int) $web->getId();
        $this->db->entityManager->clear();

        $writer = new UsageEntryWriter($this->db);
        foreach (['outbound', 'mailer'] as $channel) {
            $writer->write(new UsageCapture($channel, $old, 'GET', '/' . $channel, provider: 'p-' . $channel, tenantKey: 'org-1'));
            $this->ids[$channel] = (int) $this->db->connection()->fetchOne('SELECT MAX(id) FROM ' . $this->db->table());
        }
    }

    #[Test]
    public function listShowsOnlyAllowedChannelsAndOffersTheUsageFilters(): void
    {
        $controller = $this->controller(['web', 'outbound']);
        $controller->index(Request::create('/admin/http-log'));

        $page = $this->rendered['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertSame(['/outbound', '/web'], $this->paths($page->rows));
        self::assertSame(['usage_channels' => ['web', 'outbound', 'mailer']], $this->filterOptions);
        self::assertContains('channel', array_map(static fn (object $c): string => $c->key, $page->columns));
    }

    #[Test]
    public function deniedEntryIsA404LikeAMissingOne(): void
    {
        $controller = $this->controller(['web', 'outbound']);
        $controller->show($this->ids['outbound']);
        self::assertInstanceOf(ShowPage::class, $this->rendered['page']);

        foreach ([$this->ids['mailer'], 999999] as $id) {
            try {
                $controller->show($id);
                self::fail('expected 404 for ' . $id);
            } catch (NotFoundHttpException $exception) {
                self::assertSame('Not Found', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function exportLeavesDeniedChannelsOutAndAppendsTheUsageColumns(): void
    {
        $request = Request::create('/admin/http-log/export', 'POST', ['_token' => 'valid-token', 'format' => 'csv']);
        $content = (string) $this->controller(['mailer'], $request)->export($request)->getContent();
        $lines   = explode("\n", trim($content));

        self::assertCount(2, $lines);
        self::assertStringEndsWith('externalMessageId', $lines[0]);
        self::assertStringContainsString('/mailer', $lines[1]);
    }

    #[Test]
    public function purgeAllTouchesOnlyAllowedChannels(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', ['_token' => 'valid-token', 'purge_all' => '1']);
        $this->controller(['outbound'], $request)->purge($request);

        self::assertSame(['/web', '/mailer'], array_column($this->db->rows(), 'path'));
    }

    #[Test]
    public function purgeByDaysTouchesOnlyAllowedChannels(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', ['_token' => 'valid-token', 'older_than_days' => '7']);
        $this->controller(['web', 'mailer'], $request)->purge($request);

        self::assertSame(['/outbound'], array_column($this->db->rows(), 'path'));
    }

    #[Test]
    public function retentionPurgeRunsOnlyTheAllowedScopes(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', ['_token' => 'valid-token']);
        $this->controller(['web', 'mailer'], $request)->purge($request);
        self::assertSame(['/outbound'], array_column($this->db->rows(), 'path'));

        $nothing = Request::create('/admin/http-log/purge', 'POST', ['_token' => 'valid-token']);
        $this->controller([], $nothing)->purge($nothing);
        self::assertSame(['Purged 0 HTTP log entries.'], $nothing->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function deleteOfADeniedEntryIsA404(): void
    {
        $request = Request::create('/admin/http-log/1/delete', 'POST', ['_token' => 'valid-token']);
        $this->controller(['web'], $request)->delete($this->ids['web'], $request);
        self::assertCount(2, $this->db->rows());

        $this->expectException(NotFoundHttpException::class);
        $this->controller(['web'], $request)->delete($this->ids['mailer'], $request);
    }

    #[Test]
    public function deleteOfAMissingEntryStaysANoOp(): void
    {
        $request = Request::create('/admin/http-log/1/delete', 'POST', ['_token' => 'valid-token']);
        $this->controller(['web'], $request)->delete(999999, $request);

        self::assertCount(3, $this->db->rows());
    }

    #[Test]
    public function aPlainCheckerOrAnUnauthenticatedUiIsNotRestricted(): void
    {
        $this->controller(null)->index(Request::create('/admin/http-log'));
        self::assertCount(3, $this->rendered['page']->rows);

        $this->controller(['web'], allowUnauthenticated: true)->index(Request::create('/admin/http-log'));
        self::assertCount(3, $this->rendered['page']->rows);
    }

    /**
     * @param iterable<mixed> $rows
     *
     * @return list<string>
     */
    private function paths(iterable $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(HttpLogEntry::class, $row);
            $paths[] = $row->getPath();
        }

        return $paths;
    }

    /**
     * @param list<string>|null $allowed channels the checker allows; null = a checker without the channel interface
     */
    private function controller(?array $allowed, ?Request $request = null, bool $allowUnauthenticated = false): HttpLogAdminController
    {
        $request ??= Request::create('/admin/http-log');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $checker = $allowed === null
            ? $this->createConfiguredMock(HttpLogAccessCheckerInterface::class, ['canAccessAdmin' => true, 'canExport' => true, 'canPurge' => true])
            : new class($allowed) implements HttpLogAccessCheckerInterface, HttpLogChannelAccessCheckerInterface {
                /** @param list<string> $allowed */
                public function __construct(private readonly array $allowed)
                {
                }

                public function canAccessAdmin(?object $user): bool
                {
                    return true;
                }

                public function canExport(?object $user): bool
                {
                    return true;
                }

                public function canPurge(?object $user): bool
                {
                    return true;
                }

                public function canViewChannel(?object $user, string $channel): bool
                {
                    return in_array($channel, $this->allowed, true);
                }
            };

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $realFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->getFormFactory();
        $filterForm = $this->createMock(FormInterface::class);
        $filterForm->method('isSubmitted')->willReturn(false);
        $filterForm->method('createView')->willReturn(new FormView());
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturnCallback(function (string $type, mixed $data = null, array $options = []) use ($filterForm, $realFactory): FormInterface {
            if ($type === HttpLogFilterType::class) {
                $this->filterOptions = $options;

                return $filterForm;
            }

            return $realFactory->create($type, $data, $options);
        });

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->rendered = $parameters;

            return 'rendered';
        });
        $router = $this->createConfiguredMock(RouterInterface::class, ['generate' => '/admin/http-log']);

        $container = new ContainerBuilder();
        $container->set('request_stack', $requestStack);
        $container->set('security.token_storage', $this->createMock(TokenStorageInterface::class));
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('form.factory', $factory);
        $container->set('twig', $twig);
        $container->set('router', $router);

        $repository = $this->db->repository();
        $clock      = new MockClock(new DateTimeImmutable('2026-11-08T10:00:00+00:00'));
        $controller = new HttpLogAdminController(
            $repository,
            $checker,
            new ExportHttpLogService($repository),
            new PurgeHttpLogService($repository, $clock, ['days' => 30], ['outbound' => 30, 'mailer' => 30]),
            $this->createMock(MessageBusInterface::class),
            $allowUnauthenticated,
            100,
            20,
            [],
            null,
            ['csv', 'json'],
            $router,
            null,
            ['outbound', 'mailer'],
        );
        $controller->setContainer($container);

        return $controller;
    }
}
