<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\View;

use Nowo\HttpLogBundle\DependencyInjection\LegacyThemeBridge;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function http_build_query;
use function is_scalar;
use function is_string;
use function number_format;
use function sprintf;

/**
 * Builds the UiKit view models of the admin pages (REQ-UI-003): the list page (`admin/index.html.twig`, list
 * scaffold) and the entry page (`admin/show.html.twig`, show scaffold). Plain data in, immutable view models out —
 * no request or session state is kept (worker safe); the controller passes the forms and the permissions. Action
 * forms are read for their CSRF token (and the export criteria); the view models carry plain values.
 */
final readonly class AdminPages
{
    public const DOMAIN = LegacyThemeBridge::BUNDLE;

    /**
     * @param array<string, mixed> $webUi processed `nowo_http_log.web_ui` (layout_template, deprecated attributes)
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private array $webUi = [],
    ) {
    }

    /**
     * @param iterable<HttpLogEntry> $entries entries of the current page
     * @param array<string, FormView> $exportForms export format (csv, json) => CSRF form with the criteria (only enabled formats)
     */
    public function listPage(
        iterable $entries,
        int $total,
        int $page,
        int $pageSize,
        FormView $filters,
        array $exportForms,
        FormView $purgeForm,
        bool $filtered = false,
        bool $canExport = true,
        bool $canPurge = true,
    ): ListPage {
        $exportUrl = $this->urls->generate('nowo_http_log_admin_export');
        $purgeUrl  = $this->urls->generate('nowo_http_log_admin_purge');

        $actions = [];
        foreach ($exportForms as $format => $form) {
            // format + criteria travel in the URL, only the CSRF token is posted (see self::csrfToken()).
            $actions[] = new Action(
                'export',
                $this->label('admin.action.export_' . $format),
                href: $exportUrl . '?' . http_build_query($this->hiddenValues($form)),
                method: 'POST',
                csrfToken: $this->csrfToken($form),
                visible: $canExport,
            );
        }
        $actions[] = new Action(
            'delete',
            $this->label('admin.action.purge'),
            href: $purgeUrl,
            method: 'POST',
            csrfToken: $this->csrfToken($purgeForm),
            confirm: new Confirm($this->label('admin.action.purge'), $this->label('admin.purge.confirm'), $this->label('admin.action.purge')),
            visible: $canPurge,
        );

        return new ListPage(
            new PageHeader($this->label('admin.index.title'), $this->label('admin.index.total', ['%count%' => $total]), $actions),
            [
                new Column('id', $this->label('admin.column.id'), required: true),
                new Column('createdAt', $this->label('admin.column.created_at'), priority: 2),
                new Column('method', $this->label('admin.column.method'), required: true),
                new Column('path', $this->label('admin.column.path'), required: true),
                new Column('statusCode', $this->label('admin.column.status'), required: true),
                new Column('durationMs', $this->label('admin.column.duration'), align: 'end', priority: 2),
                new Column('clientIp', $this->label('admin.column.client_ip'), priority: 3),
            ],
            $entries,
            $filters,
            fn (HttpLogEntry $entry): array => [
                Action::view($this->urls->generate('nowo_http_log_admin_show', ['id' => $entry->getId()]), $this->label('admin.action.view')),
            ],
            new Pagination($page, $pageSize, $total, route: 'nowo_http_log_admin_index'),
            new EmptyState($this->label('admin.index.empty')),
            $filtered ? $this->urls->generate('nowo_http_log_admin_index') : null,
            bundle: LegacyThemeBridge::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'http-log-list'] + LegacyThemeBridge::pageAttributes($this->webUi, 'results'),
            filterFields: [
                'method'          => new FilterField('method', width: 'sm'),
                'routeName'       => new FilterField('routeName', width: 'md'),
                'statusCode'      => new FilterField('statusCode', width: 'xs'),
                'clientIp'        => new FilterField('clientIp', width: 'sm'),
                'path'            => new FilterField('path', width: 'md'),
                'bodyContentType' => new FilterField('bodyContentType', width: 'sm'),
                'createdFrom'     => new FilterField('createdFrom', width: 'md'),
                'createdTo'       => new FilterField('createdTo', width: 'md'),
                'q'               => new FilterField('q', width: 'lg'),
            ],
            filtersSubmit: new Action('filter', $this->label('admin.action.filter')),
        );
    }

    public function showPage(HttpLogEntry $entry, FormView $deleteForm, bool $canPurge = true): ShowPage
    {
        $indexUrl  = $this->urls->generate('nowo_http_log_admin_index');
        $deleteUrl = $this->urls->generate('nowo_http_log_admin_delete', ['id' => $entry->getId()]);
        $title     = $this->label('admin.show.title', ['%id%' => (int) $entry->getId()]);

        return new ShowPage(
            new PageHeader(
                $title,
                null,
                [
                    new Action('prev', $this->label('admin.action.back'), href: $indexUrl),
                    new Action(
                        'delete',
                        $this->label('admin.action.delete'),
                        href: $deleteUrl,
                        method: 'POST',
                        csrfToken: $this->csrfToken($deleteForm),
                        confirm: new Confirm($this->label('admin.action.delete'), $this->label('admin.delete.confirm'), $this->label('admin.action.delete')),
                        visible: $canPurge,
                    ),
                ],
                [new Crumb($this->label('admin.index.title'), $indexUrl), new Crumb($title)],
            ),
            [new DetailSection(null, $this->detailItems($entry), 'http-log-entry')],
            bundle: LegacyThemeBridge::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'http-log-show'] + LegacyThemeBridge::pageAttributes($this->webUi, 'show'),
        );
    }

    /**
     * Request / response summary of the entry page (the payloads render as code sections in the template).
     *
     * @return list<DetailItem>
     */
    private function detailItems(HttpLogEntry $entry): array
    {
        $contentType = (string) $entry->getContentType();
        $bodyType    = (string) $entry->getBodyContentType();

        return [
            new DetailItem($this->label('admin.column.id'), $entry->getId()),
            new DetailItem($this->label('admin.field.request_id'), $entry->getRequestId()),
            new DetailItem($this->label('admin.column.created_at'), $entry->getCreatedAt()->format('Y-m-d H:i:s')),
            new DetailItem($this->label('admin.column.method'), $entry->getMethod()),
            new DetailItem($this->label('admin.field.url'), sprintf('%s://%s%s', $entry->getScheme(), $entry->getHost(), $entry->getPath())),
            new DetailItem($this->label('admin.field.route'), $entry->getRouteName()),
            new DetailItem($this->label('admin.column.status'), $entry->getStatusCode()),
            new DetailItem($this->label('admin.column.duration'), $entry->getDurationMs() === null ? null : number_format($entry->getDurationMs(), 2) . ' ms'),
            new DetailItem($this->label('admin.column.client_ip'), $entry->getClientIp()),
            new DetailItem($this->label('admin.field.user'), $entry->getUserIdentifier()),
            new DetailItem($this->label('admin.field.content_type'), $contentType === '' || $bodyType === '' ? $contentType . $bodyType : sprintf('%s (%s)', $contentType, $bodyType)),
        ];
    }

    /**
     * CSRF token value of an action form (HttpLog*Type, field `_token`). Header actions carry the token as a string
     * instead of the FormView: the scaffolds render header actions more than once (pre-rendered 1.x `toolbar` and a
     * 2.0 page_header override rendering `actions`), and a FormView's fields render only once.
     */
    private function csrfToken(FormView $form): ?string
    {
        $token = $form->children['_token'] ?? null;
        $value = $token instanceof FormView ? ($token->vars['value'] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Hidden values of the export form but the CSRF token (format + criteria), for the export URL.
     *
     * @return array<string, string>
     */
    private function hiddenValues(FormView $form): array
    {
        $values = [];
        foreach ($form->children as $name => $child) {
            $value = $child->vars['value'] ?? null;
            if ($name !== '_token' && is_scalar($value) && $value !== '') {
                $values[(string) $name] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /** `web_ui.layout_template`; null = the UiKit admin layout (nowo_ui_kit.layouts.admin). */
    private function layout(): ?string
    {
        $layout = $this->webUi['layout_template'] ?? null;

        return is_string($layout) && $layout !== '' ? $layout : null;
    }
}
