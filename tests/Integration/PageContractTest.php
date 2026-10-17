<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

use function dirname;

/**
 * REQ-UI-003: every admin page extends a UiKit scaffold, fills the canonical blocks, prints framework classes only
 * through theme slots and has no raw translation keys (UiKit PageContractTestCase).
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $entries = AdminPageFixtures::entries();

        yield 'index' => new PageFixture('@NowoHttpLogBundle/admin/index.html.twig', [
            'page'     => AdminPageFixtures::listPage($entries, 7, true),
            'criteria' => ['method' => 'POST', 'statusCode' => 201],
        ]);
        yield 'index (empty)' => new PageFixture('@NowoHttpLogBundle/admin/index.html.twig', [
            'page'     => AdminPageFixtures::listPage([], 0),
            'criteria' => [],
        ]);

        $full = AdminTwigHarness::entry(7, true);
        yield 'show' => new PageFixture('@NowoHttpLogBundle/admin/show.html.twig', [
            'page'  => AdminPageFixtures::showPage($full),
            'entry' => $full,
        ]);

        $minimal = AdminTwigHarness::entry(8);
        yield 'show (no payloads)' => new PageFixture('@NowoHttpLogBundle/admin/show.html.twig', [
            'page'  => AdminPageFixtures::showPage($minimal),
            'entry' => $minimal,
        ]);
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 2) . '/src/Resources/views';

        return ['NowoHttpLogBundle' => $views, '!NowoHttpLogBundle' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }
}
