<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\DependencyInjection\LegacyThemeBridge;
use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

/**
 * REQ-UI-003: the admin pages rendered with the real UiKit scaffolds, theme and a host layout — row cells, header
 * actions (CSRF + confirm dialog), detail sections, and the 1.x hooks kept for one minor (filter / pagination
 * partials, attributes, class map bridge, page overrides).
 */
final class AdminPagesRenderTest extends TestCase
{
    #[Test]
    public function listPageRendersRowsHeaderActionsAndFilters(): void
    {
        $html = $this->render('@NowoHttpLogBundle/admin/index.html.twig', [
            'page'     => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 7, true),
            'criteria' => ['method' => 'POST', 'statusCode' => 201],
        ]);

        self::assertStringContainsString('<title>HTTP Log Entries · Host</title>', $html);
        self::assertStringContainsString('data-nowo-ui-bundle="NowoHttpLogBundle"', $html);
        self::assertStringContainsString('data-nowo-ui-page="list" data-testid="http-log-list"', $html);
        self::assertStringContainsString('7 entries', $html);
        // cells: link, method badge, status badge per class, muted missing client IP
        self::assertStringContainsString('<a href="/admin/http-log/41">41</a>', $html);
        self::assertStringContainsString('nowo-ui-badge--success badge text-bg-success ms-2 nowo-http-log-status">201<', $html);
        self::assertStringContainsString('nowo-ui-badge--warning badge text-bg-warning ms-2 nowo-http-log-status">404<', $html);
        self::assertStringContainsString('nowo-ui-badge--danger badge text-bg-danger ms-2 nowo-http-log-status">500<', $html);
        self::assertStringContainsString('nowo-http-log-method">DELETE<', $html);
        self::assertStringContainsString('812.8 ms', $html);
        self::assertStringContainsString('nowo-ui-table__cell--muted', $html);
        self::assertStringContainsString('data-label="Client IP" data-nowo-ui-priority="3"', $html);
        self::assertSame(6, substr_count($html, 'href="/admin/http-log/4'), 'id link + view row action per entry');
        // header actions: export carries format + criteria in the URL and posts the CSRF token; purge opens a dialog
        self::assertStringContainsString('action="/admin/http-log/export?format=csv&amp;method=POST&amp;statusCode=201"', $html);
        self::assertStringContainsString('action="/admin/http-log/export?format=json&amp;method=POST&amp;statusCode=201"', $html);
        self::assertSame(2, substr_count($html, 'name="_token" value="token-http_log_export"'));
        self::assertStringContainsString('name="_token" value="token-http_log_purge"', $html);
        self::assertStringContainsString('Purge HTTP log entries matching the retention policy?', $html);
        self::assertStringContainsString('<dialog', $html);
        self::assertStringNotContainsString('onsubmit', $html);
        self::assertStringNotContainsString('confirm(', $html);
        // filters: UiKit filter row, translated labels, clear link while filtered
        self::assertStringContainsString('data-nowo-ui-region="filters"', $html);
        self::assertStringContainsString('<label for="http_log_filter_routeName">Route name</label>', $html);
        self::assertStringContainsString('nowo-ui-filters__field--xs', $html);
        self::assertStringContainsString('>Filter</button>', $html);
        self::assertStringContainsString('href="/admin/http-log">Clear</a>', $html);
        self::assertStringNotContainsString('filter.method', $html);
        // pagination (7 entries, 2 per page)
        self::assertStringContainsString('data-nowo-ui-region="pagination"', $html);
        self::assertStringContainsString('page=4', $html);
    }

    #[Test]
    public function emptyListShowsTheEmptyStateWithoutClearLink(): void
    {
        $html = $this->render('@NowoHttpLogBundle/admin/index.html.twig', [
            'page'     => AdminPageFixtures::listPage([], 0),
            'criteria' => [],
        ]);

        self::assertStringContainsString('No HTTP log entries found.', $html);
        self::assertStringContainsString('data-nowo-ui-region="empty"', $html);
        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('>Clear</a>', $html);
    }

    #[Test]
    public function showPageRendersSummaryPayloadsAndDeleteDialog(): void
    {
        $entry = AdminTwigHarness::entry(7, true);
        $html  = $this->render('@NowoHttpLogBundle/admin/show.html.twig', ['page' => AdminPageFixtures::showPage($entry), 'entry' => $entry]);

        self::assertStringContainsString('<title>HTTP Log Entry #7 · Host</title>', $html);
        self::assertStringContainsString('data-nowo-ui-page="show" data-testid="http-log-show"', $html);
        self::assertStringContainsString('>https://example.test/api/items/7</dd>', $html);
        self::assertStringContainsString('>application/json (json)</dd>', $html);
        self::assertStringContainsString('>12.35 ms</dd>', $html);
        self::assertStringContainsString('id="http-log-query-params"', $html);
        self::assertStringContainsString('id="http-log-request-headers"', $html);
        self::assertStringContainsString('id="http-log-response-headers"', $html);
        self::assertStringContainsString('id="http-log-request-body"', $html);
        self::assertStringContainsString('id="http-log-response-body"', $html);
        self::assertStringContainsString('<code>{&quot;name&quot;:&quot;x&quot;}</code>', $html);
        self::assertSame(1, substr_count($html, '>truncated</span>'), 'only the request body is truncated');
        self::assertStringContainsString('href="/admin/http-log"', $html);
        self::assertStringContainsString('Back to list', $html);
        self::assertStringContainsString('action="/admin/http-log/7/delete"', $html);
        self::assertStringContainsString('name="_token" value="token-http_log_delete"', $html);
        self::assertStringContainsString('Delete this HTTP log entry?', $html);
        self::assertStringNotContainsString('onsubmit', $html);
    }

    #[Test]
    public function showPageWithoutPayloadsRendersOnlyTheSummary(): void
    {
        $entry = AdminTwigHarness::entry(8);
        $html  = $this->render('@NowoHttpLogBundle/admin/show.html.twig', ['page' => AdminPageFixtures::showPage($entry), 'entry' => $entry]);

        self::assertStringContainsString('id="http-log-entry"', $html);
        self::assertStringNotContainsString('nowo-http-log-payload', $html);
    }

    #[Test]
    public function headerActionsKeepTheirCsrfTokenWhenAPageHeaderOverrideRendersThemAgain(): void
    {
        $theme = ['partials' => ['page_header' => 'host/_page_header.html.twig']];
        $html  = $this->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []], $theme);

        self::assertStringContainsString('<header class="host-header">', $html);
        self::assertSame(2, substr_count($html, 'name="_token" value="token-http_log_export"'));
        self::assertStringContainsString('name="_token" value="token-http_log_purge"', $html);
        self::assertStringContainsString('action="/admin/http-log/export?format=csv"', $html);
    }

    #[Test]
    public function deprecatedFilterTemplateIsBridgedAndItsBlocksStillWork(): void
    {
        $theme = LegacyThemeBridge::themeException(['templates' => ['filter' => 'host/_filter.html.twig']]);
        $html  = $this->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []], ['bundles' => [LegacyThemeBridge::BUNDLE => $theme]]);

        self::assertStringContainsString('<div class="host-filter-actions"><button type="submit">Go</button></div>', $html);
        self::assertStringContainsString('data-nowo-ui-region="filters"', $html);
        self::assertStringContainsString('<label for="http_log_filter_method">Method</label>', $html, 'remaining fields render through form_end');
        self::assertStringNotContainsString('>Filter</button>', $html);
    }

    #[Test]
    public function deprecatedFilterPanelBlockReplacesTheRegion(): void
    {
        $theme = ['bundles' => [LegacyThemeBridge::BUNDLE => ['partials' => ['list_filters' => 'host/_filter_panel.html.twig']]]];
        $html  = $this->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []], $theme);

        self::assertStringContainsString('<div class="host-filter-panel"><form name="http_log_filter" method="get">', $html);
    }

    #[Test]
    public function defaultFilterPartialDelegatesToUiKit(): void
    {
        $theme = ['bundles' => [LegacyThemeBridge::BUNDLE => ['partials' => ['list_filters' => '@NowoHttpLogBundle/admin/_filter.html.twig']]]];
        $html  = $this->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []], $theme);

        self::assertStringContainsString('data-nowo-ui-filters=""', $html);
        self::assertStringContainsString('>Filter</button>', $html);
    }

    #[Test]
    public function deprecatedPaginationTemplateIsBridged(): void
    {
        $theme = LegacyThemeBridge::themeException(['templates' => ['pagination' => 'host/_pagination.html.twig']]);
        $html  = $this->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 7, page: 2), 'criteria' => []], ['bundles' => [LegacyThemeBridge::BUNDLE => $theme]]);

        self::assertStringContainsString('<p class="host-pagination" data-page="2" data-total-pages="4" data-per-page="2" data-route="nowo_http_log_admin_index"', $html);
        self::assertStringContainsString('data-items="3"', $html);
    }

    #[Test]
    public function deprecatedClassMapAndAttributesAreBridgedIntoTheTheme(): void
    {
        $webUi = [
            'css_classes' => ['table' => 'table table-dark', 'method_badge' => 'badge bg-dark'],
            'attributes'  => ['table' => ['data-testid' => 'http-log-table'], 'results' => ['data-host' => 'results']],
        ];
        $html = $this->render('@NowoHttpLogBundle/admin/index.html.twig', [
            'page'     => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3, webUi: $webUi),
            'criteria' => [],
        ], ['bundles' => [LegacyThemeBridge::BUNDLE => LegacyThemeBridge::themeException($webUi)]]);

        self::assertStringContainsString('table-dark', $html);
        self::assertStringContainsString('bg-dark nowo-http-log-method', $html);
        self::assertStringContainsString('data-testid="http-log-table"', $html);
        self::assertStringContainsString('data-testid="http-log-list" data-host="results"', $html);
    }

    #[Test]
    public function layoutTemplateSelectsTheHostLayout(): void
    {
        $entry = AdminTwigHarness::entry(9);
        $html  = $this->render('@NowoHttpLogBundle/admin/show.html.twig', [
            'page'  => AdminPageFixtures::showPage($entry, ['layout_template' => 'host/other_layout.html.twig']),
            'entry' => $entry,
        ]);

        self::assertStringContainsString('<span class="host-other-layout">other layout</span>', $html);
    }

    #[Test]
    public function pageOverridesExtendTheOriginalsAndReplaceOneBlock(): void
    {
        $index = $this->render('host/index_fork.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []]);
        self::assertStringContainsString('<p class="host-after">3 rows</p>', $index);
        self::assertStringContainsString('nowo-http-log-status', $index);

        $entry = AdminTwigHarness::entry(7, true);
        $show  = $this->render('host/show_fork.html.twig', ['page' => AdminPageFixtures::showPage($entry), 'entry' => $entry]);
        self::assertStringContainsString('<p class="host-no-body">hidden</p>', $show);
        self::assertStringNotContainsString('id="http-log-response-body"', $show);
        self::assertStringContainsString('id="http-log-request-body"', $show);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $theme
     */
    private function render(string $template, array $context, array $theme = []): string
    {
        return (new AdminTwigHarness())->createEnvironment(theme: $theme)->render($template, $context);
    }
}
