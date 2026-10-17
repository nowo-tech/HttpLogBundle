<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\View;

use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminChromeTest extends TestCase
{
    #[Test]
    public function fromWebUiReturnsDefaultsForAnEmptyNode(): void
    {
        self::assertSame(AdminChrome::DEFAULTS, AdminChrome::fromWebUi([]));
    }

    #[Test]
    public function fromWebUiKeepsOnlyChromeKeysAndMergesPartialMaps(): void
    {
        $chrome = AdminChrome::fromWebUi([
            'enabled'                  => true,
            'page_size'                => 10,
            'templates'                => ['filter' => 'host/_filter.html.twig'],
            'css_classes'              => ['table' => 'data-table'],
            'attributes'               => ['results' => ['data-testid' => 'http-log-results']],
            'ui_kit_stylesheet'        => false,
            'header_actions_in_layout' => true,
            'confirm_attributes'       => ['data-controller' => 'confirm-submit'],
            'ui_kit_row_actions'       => true,
        ]);

        self::assertArrayNotHasKey('enabled', $chrome);
        self::assertArrayNotHasKey('page_size', $chrome);
        self::assertSame('host/_filter.html.twig', $chrome['templates']['filter']);
        self::assertSame(AdminChrome::DEFAULT_TEMPLATES['index'], $chrome['templates']['index']);
        self::assertSame('data-table', $chrome['css_classes']['table']);
        self::assertSame(AdminChrome::DEFAULT_CSS_CLASSES['toolbar'], $chrome['css_classes']['toolbar']);
        self::assertSame(['results' => ['data-testid' => 'http-log-results']], $chrome['attributes']);
        self::assertFalse($chrome['ui_kit_stylesheet']);
        self::assertTrue($chrome['header_actions_in_layout']);
        self::assertSame(['data-controller' => 'confirm-submit'], $chrome['confirm_attributes']);
        self::assertTrue($chrome['ui_kit_row_actions']);
    }

    #[Test]
    public function fromWebUiIgnoresNonArrayMaps(): void
    {
        $chrome = AdminChrome::fromWebUi(['templates' => 'invalid', 'css_classes' => null]);

        self::assertSame(AdminChrome::DEFAULT_TEMPLATES, $chrome['templates']);
        self::assertSame(AdminChrome::DEFAULT_CSS_CLASSES, $chrome['css_classes']);
    }

    #[Test]
    public function fromWebUiKeepsDefaultTemplatesForUnsetDeprecatedPartials(): void
    {
        $chrome = AdminChrome::fromWebUi(['templates' => ['index' => 'host/index.html.twig', 'filter' => null, 'pagination' => null]]);

        self::assertSame('host/index.html.twig', $chrome['templates']['index']);
        self::assertSame(AdminChrome::DEFAULT_TEMPLATES['filter'], $chrome['templates']['filter']);
        self::assertSame(AdminChrome::DEFAULT_TEMPLATES['pagination'], $chrome['templates']['pagination']);
    }
}
