<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function file_get_contents;
use function substr_count;

/**
 * Spec 002 — admin chrome hooks render with a real Twig environment (forms, routing, translations, UiKit).
 *
 * Golden files under tests/Fixtures/golden were rendered by the 1.2.0 templates (pagination hrefs then updated for the
 * FR-CHR-013 filter fix): the default output must otherwise stay whitespace-equivalent. Filter form rows are rendered
 * through a label-only stub theme there (widget markup belongs to Symfony Form / FormKit); the real filter widgets are
 * pinned by {@see filterFormRendersTranslatedFieldsWithoutRawConventionKeys()}.
 */
final class AdminChromeRenderTest extends TestCase
{
    private AdminTwigHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AdminTwigHarness();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function goldenCases(): iterable
    {
        foreach (['index', 'index_empty', 'show', 'show_minimal'] as $case) {
            yield $case . ' without chrome variable' => [$case, false];
            yield $case . ' with controller defaults' => [$case, true];
        }
    }

    #[Test]
    #[DataProvider('goldenCases')]
    public function defaultOutputIsUnchanged(string $case, bool $passDefaults): void
    {
        $extra = $passDefaults ? ['nowo_http_log_ui' => AdminChrome::DEFAULTS] : [];
        $html  = $this->renderCase($this->harness->createEnvironment(stubFormRows: true), $case, $extra);

        self::assertSame(
            AdminTwigHarness::normalize((string) file_get_contents(AdminTwigHarness::FIXTURES_DIR . '/golden/' . $case . '.html')),
            AdminTwigHarness::normalize($html),
        );
    }

    #[Test]
    public function filterFormRendersTranslatedFieldsWithoutRawConventionKeys(): void
    {
        $html = $this->renderIndex([]);

        $fields = [
            'method'          => 'Method',
            'routeName'       => 'Route name',
            'statusCode'      => 'Status code',
            'clientIp'        => 'Client IP',
            'path'            => 'Path',
            'bodyContentType' => 'Body type',
            'createdFrom'     => 'Created from',
            'createdTo'       => 'Created to',
            'q'               => 'Search',
        ];
        foreach ($fields as $field => $label) {
            self::assertStringContainsString('<label for="http_log_filter_' . $field . '">' . $label . '</label>', $html);
            self::assertStringContainsString('name="http_log_filter[' . $field . ']"', $html);
        }

        // Optional selects keep an empty "any" option and never carry an HTML placeholder attribute.
        foreach (['method', 'bodyContentType'] as $select) {
            self::assertMatchesRegularExpression('#<select id="http_log_filter_' . $select . '" name="http_log_filter\[' . $select . '\]"[^>]*>\s*<option value=""\s*>\s*</option>#', $html);
            self::assertDoesNotMatchRegularExpression('#<select id="http_log_filter_' . $select . '"[^>]*placeholder=#', $html);
        }
        self::assertStringContainsString('<option value="OPTIONS">OPTIONS</option>', $html);
        self::assertStringContainsString('<option value="json">json</option>', $html);
        self::assertStringContainsString('type="datetime-local" id="http_log_filter_createdFrom"', $html);

        // No untranslated FormKit convention keys (help / placeholder) leak into the UI.
        self::assertStringNotContainsString('http_log_filter.', $html);
        self::assertStringNotContainsString('aria-describedby="http_log_filter_', $html);
    }

    #[Test]
    public function indexAppliesClassMapAndAttributes(): void
    {
        $html = $this->renderIndex([
            'css_classes' => [
                'results'         => 'panel http-log-results',
                'toolbar'         => 'mb-3',
                'table_wrapper'   => 'overflow-x-auto',
                'table'           => 'data-table w-full',
                'table_head_cell' => 'px-2 py-2',
                'table_row'       => 'row-hover',
                'table_cell'      => 'px-2 py-3',
                'path_cell'       => 'truncate',
                'method_badge'    => 'badge',
                'btn_export'      => 'btn-ghost',
                'btn_purge'       => 'btn-danger',
                'btn_view'        => 'btn-ghost btn-sm',
                'row_actions'     => 'inline-flex',
                'action_form'     => 'inline',
                'action_form_gap' => '',
                'filter'          => '',
                'filter_form'     => 'grid gap-3',
                'btn_filter'      => 'btn-primary',
            ],
            'attributes' => [
                'results'     => ['data-testid' => 'http-log-results'],
                'filter_form' => ['data-testid' => 'http-log-filters', 'role' => 'search'],
                'table'       => ['data-empty' => false],
            ],
        ]);

        self::assertStringContainsString('<div class="panel http-log-results" data-testid="http-log-results">', $html);
        self::assertStringContainsString('<div class="mb-3">', $html);
        self::assertStringContainsString('<table class="data-table w-full">', $html);
        self::assertStringContainsString('<th class="px-2 py-2">ID</th>', $html);
        self::assertStringContainsString('<tr class="row-hover">', $html);
        self::assertStringContainsString('<td class="px-2 py-3 truncate" style="max-width: 20rem;">/api/items/3</td>', $html);
        self::assertStringContainsString('class="btn-ghost nowo-ui-btn-export"', $html);
        self::assertStringContainsString('class="btn-danger nowo-ui-btn-purge"', $html);
        self::assertStringContainsString('class="btn-ghost btn-sm nowo-ui-btn-view"', $html);
        self::assertStringContainsString('<div class="inline-flex">', $html);
        self::assertSame(3, substr_count($html, 'class="inline"'));
        self::assertStringContainsString('data-testid="http-log-filters"', $html);
        self::assertStringContainsString('role="search"', $html);
        self::assertStringContainsString('class="grid gap-3"', $html);
        self::assertStringContainsString('class="btn-primary nowo-ui-btn-filter"', $html);
        self::assertStringNotContainsString('data-empty', $html);
        self::assertStringNotContainsString('card mb-4', $html);
        self::assertStringNotContainsString('table-striped', $html);
    }

    #[Test]
    public function emptyStateUsesClassMap(): void
    {
        $twig = $this->harness->createEnvironment();
        $html = $twig->render('@NowoHttpLogBundle/admin/index.html.twig', $this->harness->indexContext([], 0, extra: [
            'nowo_http_log_ui' => AdminChrome::fromWebUi(['css_classes' => ['empty_cell' => 'py-4 muted']]),
        ]));

        self::assertStringContainsString('<td colspan="8" class="py-4 muted">No HTTP log entries found.</td>', $html);
    }

    #[Test]
    public function confirmAttributesReplaceInlineOnsubmit(): void
    {
        $confirm = [
            'data-controller'                   => 'confirm-submit',
            'data-action'                       => 'submit->confirm-submit#confirm',
            'data-confirm-submit-message-value' => '{message}',
        ];

        $index = $this->renderIndex(['confirm_attributes' => $confirm]);
        self::assertStringNotContainsString('onsubmit', $index);
        self::assertStringContainsString('data-controller="confirm-submit"', $index);
        self::assertStringContainsString('data-confirm-submit-message-value="Purge HTTP log entries matching the retention policy?"', $index);

        $show = $this->renderShow(['confirm_attributes' => $confirm]);
        self::assertStringNotContainsString('onsubmit', $show);
        self::assertStringContainsString('data-confirm-submit-message-value="Delete this HTTP log entry?"', $show);
    }

    #[Test]
    public function showAppliesClassMapWrapperAndAttributes(): void
    {
        $html = $this->renderShow([
            'css_classes' => [
                'show'          => 'panel http-log-show',
                'details'       => 'http-log-details',
                'detail_term'   => '',
                'detail_value'  => '',
                'section_title' => 'nowo-ui-section-title',
                'code'          => 'nowo-ui-code',
                'back'          => 'm-0',
                'back_link'     => 'btn-ghost no-underline',
                'delete_form'   => '',
                'btn_delete'    => 'btn-danger',
            ],
            'attributes' => [
                'show'        => ['data-testid' => 'http-log-show'],
                'delete_form' => ['data-turbo' => 'false'],
            ],
        ]);

        self::assertStringContainsString('<div class="panel http-log-show" data-testid="http-log-show">', $html);
        self::assertStringContainsString('<dl class="http-log-details">', $html);
        self::assertStringContainsString('<dt>ID</dt>', $html);
        self::assertStringContainsString('<dd>7</dd>', $html);
        self::assertStringContainsString('<h2 class="nowo-ui-section-title">Query parameters</h2>', $html);
        self::assertStringContainsString('<pre class="nowo-ui-code">', $html);
        self::assertStringContainsString('<p class="m-0">', $html);
        self::assertStringContainsString('<a class="btn-ghost no-underline" href="/admin/http-log">', $html);
        self::assertStringContainsString('data-turbo="false"', $html);
        self::assertStringContainsString('class="btn-danger nowo-ui-btn-delete"', $html);
        self::assertStringNotContainsString('col-sm-3', $html);
    }

    #[Test]
    public function showWrapperIsRenderedForAttributesOnly(): void
    {
        $html = $this->renderShow(['attributes' => ['show' => ['data-testid' => 'http-log-show']]]);

        self::assertStringContainsString('<div data-testid="http-log-show">', $html);
    }

    #[Test]
    public function uiKitStylesheetCanBeDisabled(): void
    {
        self::assertStringContainsString('css/nowo-ui.css', $this->renderShow([]));
        self::assertStringNotContainsString('css/nowo-ui.css', $this->renderShow(['ui_kit_stylesheet' => false]));
    }

    #[Test]
    public function headerActionsMoveToTheBundleLayout(): void
    {
        $index = $this->renderIndex(['header_actions_in_layout' => true]);

        self::assertSame(1, substr_count($index, 'nowo-ui-btn-purge'));
        self::assertMatchesRegularExpression('#<header class="nowo-ui-header mb-4">.*<div class="nowo-ui-header-actions">.*nowo-ui-btn-export.*nowo-ui-btn-purge.*</header>#s', $index);
        self::assertMatchesRegularExpression('#<div class="nowo-ui-toolbar[^"]*">\s*<p class="nowo-ui-summary mb-0">5 entries</p>\s*</div>#', $index);

        $show = $this->renderShow(['header_actions_in_layout' => true]);
        self::assertMatchesRegularExpression('#<div class="nowo-ui-header-actions">\s*<p class="nowo-ui-back mb-3">#', $show);
        self::assertSame(1, substr_count($show, 'nowo-ui-back'));
    }

    #[Test]
    public function headerActionsMoveToAHostLayoutBridge(): void
    {
        $globals = ['nowo_http_log_layout_template' => 'host/layout.html.twig'];

        $index = $this->renderIndex(['header_actions_in_layout' => true, 'ui_kit_stylesheet' => false], $globals);
        self::assertMatchesRegularExpression('#<header class="host-header">\s*<div class="nowo-ui-actions">.*nowo-ui-btn-purge.*</header>#s', $index);
        self::assertSame(1, substr_count($index, 'nowo-ui-btn-purge'));
        self::assertStringNotContainsString('nowo-ui.css', $index);

        $show = $this->renderShow(['header_actions_in_layout' => true], $globals);
        self::assertMatchesRegularExpression('#<header class="host-header">\s*<p class="nowo-ui-back mb-3">#', $show);
    }

    #[Test]
    public function rowActionsCanUseTheUiKitPartial(): void
    {
        $html = $this->renderIndex(['ui_kit_row_actions' => true]);

        self::assertStringNotContainsString('nowo-ui-btn-view', $html);
        self::assertStringContainsString('nowo-ui-row-actions--', $html);
        self::assertStringContainsString('href="/admin/http-log/3"', $html);
        self::assertStringContainsString('View', $html);
    }

    #[Test]
    public function paginationPartialPathReceivesTheUiKitContract(): void
    {
        $html = $this->renderIndex(['templates' => ['pagination' => 'host/_pagination.html.twig']]);

        self::assertStringNotContainsString('nowo-ui-pagination', $html);
        self::assertStringContainsString('class="host-pagination"', $html);
        self::assertStringContainsString('data-page="2"', $html);
        self::assertStringContainsString('data-pages="3"', $html);
        self::assertStringContainsString('data-total-pages="3"', $html);
        self::assertStringContainsString('data-per-page="2"', $html);
        self::assertStringContainsString('data-route="nowo_http_log_admin_index"', $html);
        self::assertStringContainsString('data-items="2"', $html);
        self::assertStringContainsString('data-nav="HTTP Log Entries"', $html);
        self::assertStringContainsString('2026-08-01T09:30', $html);
        self::assertStringContainsString('data-query="{&quot;http_log_filter&quot;:{&quot;method&quot;:&quot;GET&quot;', $html);
    }

    #[Test]
    public function paginationPartialGetsOnePageWhenEmpty(): void
    {
        $twig = $this->harness->createEnvironment();
        $html = $twig->render('@NowoHttpLogBundle/admin/index.html.twig', $this->harness->indexContext([], 0, extra: [
            'nowo_http_log_ui' => AdminChrome::fromWebUi(['templates' => ['pagination' => 'host/_pagination.html.twig']]),
        ]));

        self::assertStringContainsString('data-pages="1"', $html);
    }

    #[Test]
    public function filterPartialPathCanExtendTheBundlePartial(): void
    {
        $html = $this->renderIndex(['templates' => ['filter' => 'host/_filter.html.twig']]);

        self::assertStringContainsString('<div class="host-filter-actions">', $html);
        self::assertStringNotContainsString('nowo-ui-btn-filter', $html);
        self::assertStringContainsString('name="http_log_filter[method]"', $html);
        self::assertStringContainsString('nowo-ui-filter card mb-4', $html);
    }

    #[Test]
    public function thinForkOverridesOnlyBlocks(): void
    {
        $twig = $this->harness->createEnvironment();

        $index = $twig->render('host/index_fork.html.twig', $this->harness->indexContext([AdminTwigHarness::entry(3)], 1));
        self::assertStringContainsString('<p class="host-intro">Every request, newest first.</p>', $index);
        self::assertStringContainsString('<a class="host-view" href="/admin/http-log/3">#3</a>', $index);
        self::assertStringNotContainsString('nowo-ui-btn-view', $index);
        self::assertStringContainsString('nowo-ui-btn-purge', $index);

        $empty = $twig->render('host/index_fork.html.twig', $this->harness->indexContext([], 0));
        self::assertStringContainsString('<td class="host-empty" colspan="8">Nothing yet</td>', $empty);

        $show = $twig->render('host/show_fork.html.twig', $this->harness->showContext(AdminTwigHarness::entry(7, true)));
        self::assertStringContainsString('<p class="host-no-body">hidden</p>', $show);
        self::assertStringNotContainsString('Response body', $show);
        self::assertStringContainsString('Request body', $show);
    }

    /**
     * @param array<string, mixed> $webUi
     * @param array<string, mixed> $globals
     */
    private function renderIndex(array $webUi, array $globals = []): string
    {
        return $this->harness->createEnvironment($globals)->render(
            '@NowoHttpLogBundle/admin/index.html.twig',
            $this->harness->indexContext([AdminTwigHarness::entry(3), AdminTwigHarness::entry(4)], 5, 2, extra: [
                'nowo_http_log_ui' => AdminChrome::fromWebUi($webUi),
            ]),
        );
    }

    /**
     * @param array<string, mixed> $webUi
     * @param array<string, mixed> $globals
     */
    private function renderShow(array $webUi, array $globals = []): string
    {
        return $this->harness->createEnvironment($globals)->render(
            '@NowoHttpLogBundle/admin/show.html.twig',
            $this->harness->showContext(AdminTwigHarness::entry(7, true), [
                'nowo_http_log_ui' => AdminChrome::fromWebUi($webUi),
            ]),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderCase(Environment $twig, string $case, array $extra): string
    {
        return match ($case) {
            'index'       => $twig->render('@NowoHttpLogBundle/admin/index.html.twig', $this->harness->indexContext([AdminTwigHarness::entry(3), AdminTwigHarness::entry(4)], 5, 2, extra: $extra)),
            'index_empty' => $twig->render('@NowoHttpLogBundle/admin/index.html.twig', $this->harness->indexContext([], 0, extra: $extra)),
            'show'        => $twig->render('@NowoHttpLogBundle/admin/show.html.twig', $this->harness->showContext(AdminTwigHarness::entry(7, true), $extra)),
            default       => $twig->render('@NowoHttpLogBundle/admin/show.html.twig', $this->harness->showContext(AdminTwigHarness::entry(8), $extra)),
        };
    }
}
