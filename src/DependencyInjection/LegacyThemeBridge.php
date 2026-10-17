<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use Nowo\HttpLogBundle\View\AdminChrome;

use function array_unique;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_split;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Bridges the deprecated 1.x `web_ui` chrome keys (`css_classes`, `attributes`, `templates.filter|pagination`) into
 * the UiKit per-bundle theme exception `nowo_ui_kit.theme.bundles.NowoHttpLogBundle` (REQ-UI-003), so hosts that
 * customised the 1.x chrome keep their look for one minor version. Removed in 2.0.
 *
 * Only values a host changed are bridged: the 1.x defaults were complete framework classes that the UiKit slot
 * defaults already provide.
 */
final class LegacyThemeBridge
{
    public const BUNDLE = 'NowoHttpLogBundle';

    /** 1.x `web_ui.css_classes` key => UiKit theme slot. Keys not listed have no slot (see UPGRADING). */
    public const CLASS_SLOTS = [
        'filter'          => 'filters',
        'filter_form'     => 'filters',
        'btn_filter'      => 'btn_primary',
        'toolbar'         => 'toolbar',
        'summary'         => 'page_intro',
        'btn_export'      => 'btn_secondary',
        'btn_purge'       => 'btn_danger',
        'btn_delete'      => 'btn_danger',
        'table_wrapper'   => 'table_wrap',
        'table'           => 'table',
        'table_head_row'  => 'table_head_row',
        'table_head_cell' => 'table_head_cell',
        'table_row'       => 'table_row',
        'table_cell'      => 'table_cell',
        'method_badge'    => 'badge_neutral',
        'row_actions'     => 'row_actions',
        'btn_view'        => 'action_neutral',
        'empty_cell'      => 'empty',
        'pagination'      => 'pagination',
        'page_link'       => 'pagination_link',
        'details'         => 'detail_list',
        'detail_term'     => 'detail_term',
        'detail_value'    => 'detail_value',
        'section_title'   => 'card_title',
    ];

    /** 1.x `web_ui.attributes` target => UiKit theme slot (`results` / `show` go on the page model instead). */
    public const ATTRIBUTE_SLOTS = [
        'filter'        => 'filters',
        'filter_form'   => 'filters',
        'toolbar'       => 'toolbar',
        'table_wrapper' => 'table_wrap',
        'table'         => 'table',
        'details'       => 'detail_list',
    ];

    /** 1.x `web_ui.templates` partial key => UiKit theme partial name. */
    public const PARTIALS = [
        'filter'     => 'list_filters',
        'pagination' => 'pagination',
    ];

    /** 1.x default partial templates: setting them explicitly bridges nothing. */
    public const LEGACY_DEFAULT_PARTIALS = [
        'filter'     => '@NowoHttpLogBundle/admin/_filter.html.twig',
        'pagination' => '@NowoHttpLogBundle/admin/_pagination.html.twig',
    ];

    /**
     * UiKit theme exception for this bundle from a processed `web_ui` node (empty array when nothing to bridge).
     *
     * @param array<string, mixed> $webUi
     *
     * @return array{classes?: array<string, string>, attributes?: array<string, array<string, string>>, partials?: array<string, string>}
     */
    public static function themeException(array $webUi): array
    {
        $theme = [];

        $classes = self::classes(is_array($webUi['css_classes'] ?? null) ? $webUi['css_classes'] : []);
        if ($classes !== []) {
            $theme['classes'] = $classes;
        }

        $attributes = self::attributes(is_array($webUi['attributes'] ?? null) ? $webUi['attributes'] : []);
        if ($attributes !== []) {
            $theme['attributes'] = $attributes;
        }

        $partials = self::partials(is_array($webUi['templates'] ?? null) ? $webUi['templates'] : []);
        if ($partials !== []) {
            $theme['partials'] = $partials;
        }

        return $theme;
    }

    /**
     * Extra attributes of a 1.x page wrapper target (`results` → list page, `show` → detail page) for the page model.
     *
     * @param array<string, mixed> $webUi
     *
     * @return array<string, string>
     */
    public static function pageAttributes(array $webUi, string $target): array
    {
        $attributes = is_array($webUi['attributes'] ?? null) ? $webUi['attributes'] : [];

        return self::safeAttributes(is_array($attributes[$target] ?? null) ? $attributes[$target] : []);
    }

    /**
     * @param array<string, mixed> $cssClasses
     *
     * @return array<string, string>
     */
    private static function classes(array $cssClasses): array
    {
        /** @var array<string, list<string>> $bySlot */
        $bySlot = [];
        foreach (self::CLASS_SLOTS as $key => $slot) {
            $value = $cssClasses[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || $value === (AdminChrome::DEFAULT_CSS_CLASSES[$key] ?? null)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                $bySlot[$slot][] = $class;
            }
        }

        $classes = [];
        foreach ($bySlot as $slot => $list) {
            $classes[$slot] = implode(' ', array_unique($list));
        }

        return $classes;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, array<string, string>>
     */
    private static function attributes(array $attributes): array
    {
        $bySlot = [];
        foreach (self::ATTRIBUTE_SLOTS as $target => $slot) {
            $safe = self::safeAttributes(is_array($attributes[$target] ?? null) ? $attributes[$target] : []);
            if ($safe !== []) {
                $bySlot[$slot] = ($bySlot[$slot] ?? []) + $safe;
            }
        }

        return $bySlot;
    }

    /**
     * @param array<string, mixed> $templates
     *
     * @return array<string, string>
     */
    private static function partials(array $templates): array
    {
        $partials = [];
        foreach (self::PARTIALS as $key => $partial) {
            $template = $templates[$key] ?? null;
            if (is_string($template) && $template !== '' && $template !== self::LEGACY_DEFAULT_PARTIALS[$key]) {
                $partials[$partial] = $template;
            }
        }

        return $partials;
    }

    /**
     * Scalar attributes without inline event handlers (UiKit rejects `on*`); `false` drops the attribute (1.x).
     *
     * @param array<mixed> $attributes
     *
     * @return array<string, string>
     */
    private static function safeAttributes(array $attributes): array
    {
        $safe = [];
        foreach ($attributes as $name => $value) {
            if (is_string($name) && !str_starts_with(strtolower($name), 'on') && is_scalar($value) && $value !== false) {
                $safe[$name] = (string) $value;
            }
        }

        return $safe;
    }
}
