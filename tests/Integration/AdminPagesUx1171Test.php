<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Form\HttpLogExportType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use Nowo\HttpLogBundle\View\AdminPages;
use Nowo\UiKitBundle\View\Action;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

/**
 * 1.25 (Beacon UX review): translated, named "Any ..." options, the opt-in UiKit filter bar with `_filter_actions`,
 * the opt-in split header (Purge in the more menu) and the opt-in `page_actions` menu. Defaults keep the 1.24 output.
 */
final class AdminPagesUx1171Test extends TestCase
{
    #[Test]
    public function selectsOfferTranslatedNamedAnyOptionsAndEveryFieldHasALabel(): void
    {
        $html = $this->render(['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3)]);

        self::assertStringContainsString('<option value="">Any method</option>', $html);
        self::assertStringContainsString('<option value="">Any body type</option>', $html);
        self::assertStringNotContainsString('filter.any', $html);
        foreach (['q' => 'Search', 'method' => 'Method', 'statusCode' => 'Status code', 'clientIp' => 'Client IP', 'path' => 'Path', 'routeName' => 'Route name', 'bodyContentType' => 'Body type', 'createdFrom' => 'Created from', 'createdTo' => 'Created to'] as $field => $label) {
            self::assertStringContainsString('<label for="http_log_filter_' . $field . '">' . $label . '</label>', $html);
        }
        self::assertStringContainsString('placeholder="203.0.113.7"', $html);
    }

    #[Test]
    public function defaultsKeepTheScaffoldFilterRowAndTheToolbarHeader(): void
    {
        $html = $this->render(['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3)]);

        self::assertStringContainsString('nowo-ui-filters__submit', $html);
        self::assertStringNotContainsString('nowo-ui-filter-bar', $html);
        self::assertStringNotContainsString('data-nowo-ui-filter-actions', $html);
        self::assertStringNotContainsString('data-nowo-ui-page-actions', $html);
        self::assertStringNotContainsString('nowo-http-log-page-actions', $html);
        // the 1.24 toolbar: Purge is a header button next to the exports
        self::assertStringNotContainsString('data-nowo-ui-header-more', $html);
    }

    #[Test]
    public function filterBarModeRendersTheUiKitFilterActionsPartialWithAlwaysTheClearLink(): void
    {
        $html = $this->render(['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'filter_actions' => AdminPages::FILTER_ACTIONS_FILTER_BAR]);

        self::assertStringContainsString('class="nowo-ui-filter-bar"', $html);
        self::assertStringContainsString('data-nowo-ui-filter-actions', $html);
        self::assertStringContainsString('nowo-ui-filter-bar__submit', $html);
        self::assertStringContainsString('nowo-ui-filter-bar__clear', $html);
        self::assertStringContainsString('nowo-ui-filter-bar__submit">Filter</button>', $html);
        self::assertStringContainsString('href="/admin/http-log">Clear</a>', $html);
        self::assertStringNotContainsString('nowo-ui-filters__submit', $html);
        self::assertStringContainsString('<label for="http_log_filter_method">Method</label>', $html);
        self::assertStringContainsString('<option value="">Any method</option>', $html);
        self::assertStringContainsString('nowo-ui-filter-bar__field--search', $html);
        // a role="search" form closes properly: exactly one filter form opened by the bar
        self::assertSame(1, substr_count($html, 'class="nowo-ui-filter-bar"'));
    }

    #[Test]
    public function splitModeMovesExportAndPurgeIntoTheMoreMenuKeepingTheConfirmation(): void
    {
        $page = AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3, false, ['header_actions' => AdminPages::HEADER_ACTIONS_SPLIT]);
        $html = $this->render(['page' => $page]);

        self::assertSame('split', $page->header->actionsLayout);
        self::assertStringContainsString('data-nowo-ui-header-more', $html);
        self::assertStringContainsString('nowo-ui-menu__item--danger nowo-ui-action--delete', $html);
        self::assertStringContainsString('Purge HTTP log entries matching the retention policy?', $html);
        self::assertStringContainsString('<dialog', $html);
        self::assertStringContainsString('name="_token" value="token-http_log_purge"', $html);
        // destructive last in the menu: Purge after the exports
        self::assertGreaterThan(strpos($html, 'Export JSON'), strpos($html, 'Purge old entries'));
    }

    #[Test]
    public function pageActionsModeDropsTheHeaderButtonsAndRendersTheUiKitPageActionsMenu(): void
    {
        $webUi = ['header_actions' => AdminPages::HEADER_ACTIONS_PAGE_ACTIONS];
        $pages = AdminPageFixtures::pages($webUi);
        $page  = AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3, false, $webUi);
        $forms = (new AdminTwigHarness())->formFactory();
        $items = $pages->headerActions(
            [
                'csv' => $forms->create(HttpLogExportType::class, null, ['action' => '/admin/http-log/export', 'format' => 'csv', 'criteria' => []])->createView(),
            ],
            $forms->create(HttpLogPurgeType::class, null, ['action' => '/admin/http-log/purge'])->createView(),
        );

        self::assertSame([], $page->header->actions);
        self::assertContainsOnlyInstancesOf(Action::class, $items);
        self::assertCount(2, $items);

        $html = $this->render(['page' => $page, 'page_actions' => $items]);

        self::assertStringContainsString('nowo-http-log-page-actions', $html);
        self::assertStringContainsString('data-nowo-ui-page-actions', $html);
        self::assertStringContainsString('Export CSV', $html);
        self::assertStringContainsString('Purge HTTP log entries matching the retention policy?', $html);
    }

    #[Test]
    public function modeAccessorsFallBackToTheDefaultsForUnknownValues(): void
    {
        self::assertSame('toolbar', AdminPageFixtures::pages()->headerActionsMode());
        self::assertSame('toolbar', AdminPageFixtures::pages(['header_actions' => 'bogus'])->headerActionsMode());
        self::assertSame('split', AdminPageFixtures::pages(['header_actions' => 'split'])->headerActionsMode());
        self::assertSame('scaffold', AdminPageFixtures::pages()->filterActionsMode());
        self::assertSame('scaffold', AdminPageFixtures::pages(['filter_actions' => 'bogus'])->filterActionsMode());
        self::assertSame('filter_bar', AdminPageFixtures::pages(['filter_actions' => 'filter_bar'])->filterActionsMode());
    }

    /** @param array<string, mixed> $context */
    private function render(array $context): string
    {
        return (new AdminTwigHarness())->createEnvironment()->render('@NowoHttpLogBundle/admin/index.html.twig', $context + ['criteria' => []]);
    }
}
