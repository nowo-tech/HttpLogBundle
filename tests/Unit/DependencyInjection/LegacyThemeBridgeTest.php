<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\LegacyThemeBridge;
use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REQ-UI-003 BC: the deprecated 1.x chrome keys map onto UiKit theme slots / partials of the bundle exception.
 */
final class LegacyThemeBridgeTest extends TestCase
{
    #[Test]
    public function defaultsAndEmptyValuesBridgeNothing(): void
    {
        self::assertSame([], LegacyThemeBridge::themeException([]));
        self::assertSame([], LegacyThemeBridge::themeException([
            'css_classes' => AdminChrome::DEFAULT_CSS_CLASSES,
            'attributes'  => [],
            'templates'   => ['index' => 'x.html.twig', 'filter' => null, 'pagination' => null],
        ]));
        self::assertSame([], LegacyThemeBridge::themeException([
            'css_classes' => ['table' => '  ', 'btn_view' => null],
            'templates'   => LegacyThemeBridge::LEGACY_DEFAULT_PARTIALS,
            'attributes'  => ['results' => ['data-testid' => 'x'], 'delete_form' => ['data-x' => '1']],
        ]), 'page wrapper / dropped targets are not slots');
    }

    #[Test]
    public function changedClassesMapOntoSlotsAndMergeSharedSlots(): void
    {
        $theme = LegacyThemeBridge::themeException(['css_classes' => [
            'filter'        => 'host-filter card',
            'filter_form'   => 'host-filter host-filter--row',
            'btn_purge'     => 'btn btn-danger',
            'btn_delete'    => 'btn btn-danger btn-lg',
            'method_badge'  => 'badge bg-dark',
            'section_title' => 'h6',
            'path_cell'     => 'text-break',
            'code'          => 'bg-dark',
        ]]);

        self::assertSame([
            'filters'       => 'host-filter card host-filter--row',
            'btn_danger'    => 'btn btn-danger btn-lg',
            'badge_neutral' => 'badge bg-dark',
            'card_title'    => 'h6',
            'code_block'    => 'bg-dark',
        ], $theme['classes'] ?? null);
        self::assertArrayNotHasKey('attributes', $theme);
        self::assertArrayNotHasKey('partials', $theme);
    }

    #[Test]
    public function attributesMapOntoSlotsWithoutEventHandlers(): void
    {
        $theme = LegacyThemeBridge::themeException(['attributes' => [
            'filter'        => ['data-a' => '1'],
            'filter_form'   => ['data-b' => '2', 'role' => 'search', 'onsubmit' => 'evil()'],
            'table_wrapper' => ['data-c' => 3, 'hidden' => false, 'title' => null],
            'details'       => ['data-testid' => 'details'],
        ]]);

        self::assertSame([
            'filters'     => ['data-a' => '1', 'data-b' => '2', 'role' => 'search'],
            'table_wrap'  => ['data-c' => '3'],
            'detail_list' => ['data-testid' => 'details'],
        ], $theme['attributes'] ?? null);
    }

    #[Test]
    public function customPartialTemplatesBecomeThemePartials(): void
    {
        self::assertSame(
            ['partials' => ['list_filters' => 'host/_filter.html.twig', 'pagination' => 'kit/_pagination.html.twig']],
            LegacyThemeBridge::themeException(['templates' => ['filter' => 'host/_filter.html.twig', 'pagination' => 'kit/_pagination.html.twig']]),
        );
    }

    #[Test]
    public function pageAttributesComeFromThePageWrapperTargets(): void
    {
        $webUi = ['attributes' => ['results' => ['data-testid' => 'results', 'onclick' => 'x()'], 'show' => ['data-x' => true]]];

        self::assertSame(['data-testid' => 'results'], LegacyThemeBridge::pageAttributes($webUi, 'results'));
        self::assertSame(['data-x' => '1'], LegacyThemeBridge::pageAttributes($webUi, 'show'));
        self::assertSame([], LegacyThemeBridge::pageAttributes([], 'results'));
        self::assertSame([], LegacyThemeBridge::pageAttributes(['attributes' => 'invalid'], 'results'));
    }
}
