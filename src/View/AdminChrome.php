<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\View;

use Nowo\HttpLogBundle\DependencyInjection\LegacyThemeBridge;

use function array_filter;
use function array_intersect_key;
use function array_replace;
use function is_array;

/**
 * @deprecated since 1.8, removed in 2.0: the admin pages render through the UiKit page contract (REQ-UI-003) and the
 *             1.x keys are bridged into nowo_ui_kit.theme.bundles.NowoHttpLogBundle
 *             ({@see LegacyThemeBridge}). Only the deprecated
 *             `nowo_http_log.web_ui.chrome` container parameter is still built from it.
 *
 * Admin chrome hooks (spec 002): default template paths, CSS class map and presentation switches.
 *
 * Single source of truth for the `web_ui.templates`, `web_ui.css_classes`, `web_ui.ui_kit_stylesheet`,
 * `web_ui.attributes`, `web_ui.header_actions_in_layout`, `web_ui.confirm_attributes` and `web_ui.ui_kit_row_actions`
 * defaults of the 1.x chrome — still the defaults of those deprecated configuration nodes.
 */
final class AdminChrome
{
    /** Template paths the controller renders / the pages include. */
    public const DEFAULT_TEMPLATES = [
        'index'  => '@NowoHttpLogBundle/admin/index.html.twig',
        'show'   => '@NowoHttpLogBundle/admin/show.html.twig',
        'filter' => '@NowoHttpLogBundle/admin/_filter.html.twig',
        // 1.8: the local pagination partial is gone; the list scaffold renders the UiKit one.
        'pagination' => '@NowoUiKitBundle/partials/_pagination.html.twig',
    ];

    /**
     * CSS class map. Defaults equal the markup shipped before 1.3.0.
     * `null` = use the UiKit macro for the configured css_framework (ui.btn / ui.row_actions).
     * `''` = render no class attribute (and, for `results` / `show`, no wrapper element).
     */
    public const DEFAULT_CSS_CLASSES = [
        // Filter partial
        'filter'      => 'nowo-ui-filter card mb-4',
        'filter_body' => 'card-body',
        'filter_form' => 'nowo-ui-filter-form row g-2 align-items-end',
        'btn_filter'  => null,
        // List page: wrapper, toolbar, header actions
        'results'         => '',
        'toolbar'         => 'nowo-ui-toolbar d-flex justify-content-between align-items-center mb-3',
        'summary'         => 'nowo-ui-summary mb-0',
        'actions'         => 'nowo-ui-actions',
        'action_form'     => 'd-inline',
        'action_form_gap' => 'ms-1',
        'btn_export'      => null,
        'btn_purge'       => null,
        // List page: table
        'table_wrapper'   => 'nowo-ui-table-wrap table-responsive',
        'table'           => 'table table-striped table-sm nowo-ui-table',
        'table_head_row'  => '',
        'table_head_cell' => '',
        'table_row'       => '',
        'table_cell'      => '',
        'method_badge'    => 'badge bg-secondary',
        'path_cell'       => 'text-truncate',
        'row_actions'     => null,
        'btn_view'        => null,
        'empty_cell'      => 'text-muted',
        // List page: default pagination partial
        'pagination'      => 'nowo-ui-pagination mt-3',
        'pagination_list' => 'pagination',
        'page_item'       => 'page-item',
        'page_link'       => 'page-link',
        // Detail page
        'show'          => '',
        'back'          => 'nowo-ui-back mb-3',
        'back_link'     => '',
        'details'       => 'row nowo-ui-details',
        'detail_term'   => 'col-sm-3',
        'detail_value'  => 'col-sm-9',
        'section_title' => 'h5 nowo-ui-section-title',
        'code'          => 'nowo-ui-code bg-light p-3',
        'delete_form'   => 'mt-4',
        'btn_delete'    => null,
    ];

    /**
     * Elements that accept extra HTML attributes via `web_ui.attributes` (e.g. data-testid, role).
     *
     * @var list<string>
     */
    public const ATTRIBUTE_TARGETS = [
        'filter',
        'filter_form',
        'results',
        'toolbar',
        'table_wrapper',
        'table',
        'show',
        'details',
        'delete_form',
    ];

    /** Resolved chrome passed to Twig as `nowo_http_log_ui`. */
    public const DEFAULTS = [
        'templates'                => self::DEFAULT_TEMPLATES,
        'css_classes'              => self::DEFAULT_CSS_CLASSES,
        'attributes'               => [],
        'ui_kit_stylesheet'        => true,
        'header_actions_in_layout' => false,
        'confirm_attributes'       => [],
        'ui_kit_row_actions'       => false,
    ];

    /**
     * Extracts the chrome keys from a processed `web_ui` config node, filling gaps with defaults.
     *
     * @param array<string, mixed> $webUi
     *
     * @return array<string, mixed>
     */
    public static function fromWebUi(array $webUi): array
    {
        $chrome = array_replace(self::DEFAULTS, array_intersect_key($webUi, self::DEFAULTS));

        foreach (['templates' => self::DEFAULT_TEMPLATES, 'css_classes' => self::DEFAULT_CSS_CLASSES] as $key => $defaults) {
            $chrome[$key] = array_replace($defaults, array_filter(is_array($chrome[$key]) ? $chrome[$key] : [], static fn (mixed $v): bool => $v !== null || $key === 'css_classes'));
        }

        return $chrome;
    }
}
