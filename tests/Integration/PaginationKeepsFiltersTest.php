<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Model\HttpLogListCursor;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\MessageBusInterface;

use function html_entity_decode;
use function preg_match;

/**
 * Spec 002 FR-CHR-013 (fixed in 1.3.0), UiKit pagination since 1.8: pagination links carry the active filters in
 * the shape the GET filter form reads (http_log_filter[field]=…), so page 2 is still filtered.
 *
 * Real controller + real filter form + real Twig templates; only the repository is a test double.
 */
final class PaginationKeepsFiltersTest extends TestCase
{
    #[Test]
    public function nextPageLinkKeepsTheActiveFilters(): void
    {
        $harness = new AdminTwigHarness();
        $calls   = [];

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('findListRows')->willReturnCallback(
            static function (array $criteria, int $page, int $pageSize, ?HttpLogListCursor $cursor) use (&$calls): array {
                $calls[] = ['criteria' => $criteria, 'page' => $page, 'cursor' => $cursor];
                $row     = new HttpLogListRow(10 - $page, new DateTimeImmutable('2026-08-03 10:0' . $page . ':00'), 'POST', '/api/items/' . $page, 201);

                return ['items' => [$row], 'total' => 5, 'total_capped' => false, 'page' => $page, 'cursor' => HttpLogListCursor::fromRows($page, $row)];
            },
        );

        $first = Request::create('/admin/http-log', 'GET', [
            'http_log_filter' => ['method' => 'POST', 'statusCode' => '201', 'createdFrom' => '2026-08-01T09:30'],
        ]);
        $html = (string) $this->controller($harness, $repository, $first)->index($first)->getContent();

        self::assertSame(1, preg_match('#<a class="[^"]*nowo-ui-pagination__link[^"]*" href="([^"]+)" aria-label="Next">#', $html, $match));
        $nextUrl = html_entity_decode($match[1]);
        self::assertStringContainsString('http_log_filter[method]=POST', urldecode($nextUrl));
        self::assertStringContainsString('page=2', $nextUrl);
        self::assertStringContainsString('cursor=1.20260803100100.9.20260803100100.9', $nextUrl, 'The link carries the keyset position of page 1.');

        $second   = Request::create($nextUrl, 'GET');
        $pageTwo  = (string) $this->controller($harness, $repository, $second)->index($second)->getContent();
        $expected = ['method' => 'POST', 'statusCode' => 201, 'createdFrom' => new DateTimeImmutable('2026-08-01T09:30')];

        self::assertCount(2, $calls);
        self::assertSame(1, $calls[0]['page']);
        self::assertSame(2, $calls[1]['page']);
        self::assertNull($calls[0]['cursor']);
        self::assertSame('1.20260803100100.9.20260803100100.9', $calls[1]['cursor']?->toString());
        self::assertStringContainsString('cursor=2.20260803100200.8.20260803100200.8', $pageTwo, 'Page 2 links carry page 2\'s position, not the request\'s.');
        self::assertEquals($expected, $calls[0]['criteria']);
        self::assertEquals($expected, $calls[1]['criteria'], 'Page 2 must still be filtered.');
        self::assertStringContainsString('http_log_filter%5Bmethod%5D=POST', $pageTwo, 'Page 2 links keep the filters too.');
        self::assertStringContainsString('value="POST" selected="selected"', $pageTwo);
    }

    private function controller(AdminTwigHarness $harness, HttpLogEntryRepository $repository, Request $request): HttpLogAdminController
    {
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new ContainerBuilder();
        $container->set('request_stack', $requestStack);
        $container->set('form.factory', $harness->formFactory());
        $container->set('twig', $harness->createEnvironment(requestStack: $requestStack));
        $router = $harness->router();
        $container->set('router', $router);

        $controller = new HttpLogAdminController(
            $repository,
            $this->createMock(HttpLogAccessCheckerInterface::class),
            $this->createMock(ExportHttpLogService::class),
            $this->createMock(PurgeHttpLogService::class),
            $this->createMock(MessageBusInterface::class),
            true,
            100,
            1,
            urlGenerator: $router,
        );
        $controller->setContainer($container);

        return $controller;
    }
}
