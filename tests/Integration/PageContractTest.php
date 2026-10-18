<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\DataCollectorTranslator;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
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

    /**
     * @return iterable<string, array{PageFixture}>
     */
    public static function translationPages(): iterable
    {
        foreach (self::pageFixtures() as $name => $fixture) {
            yield $name => [$fixture];
        }
    }

    /**
     * The form theme translates choice labels and placeholders in the field domain: the literal HTTP methods and body
     * types of the filter are not looked up, and the placeholders are catalogue keys translated once (they were
     * translated by the page model and again by the theme; the profiler logged missing messages).
     */
    #[DataProvider('translationPages')]
    public function testPageLooksUpNoMissingMessage(PageFixture $fixture): void
    {
        $twig      = $this->createTwig();
        $collector = $twig->getExtension(TranslationExtension::class)->getTranslator();
        assert($collector instanceof DataCollectorTranslator);
        $html = $twig->render($fixture->template, $fixture->context);

        $missing = array_filter(
            $collector->getCollectedMessages(),
            static fn (array $message): bool => $message['state'] === DataCollectorTranslator::MESSAGE_MISSING,
        );
        self::assertSame([], array_values(array_map(static fn (array $message): string => $message['domain'] . ': ' . $message['id'], $missing)));
        if ($fixture->template === '@NowoHttpLogBundle/admin/index.html.twig') {
            self::assertStringContainsString('<option value="DELETE">DELETE</option>', $html);
            self::assertStringContainsString('<option value="json">json</option>', $html);
            self::assertStringContainsString('placeholder="Path, route or IP"', $html);
            self::assertStringContainsString('placeholder="/api/"', $html);
        }
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
