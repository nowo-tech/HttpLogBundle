<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use LogicException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Form\AbstractHttpLogActionType;
use Nowo\HttpLogBundle\Form\HttpLogDeleteType;
use Nowo\HttpLogBundle\Form\HttpLogExportType;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Security\HttpLogChannelAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\ExportFileStore;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\View\AdminPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_intersect;
use function array_map;
use function array_sum;
use function array_values;
use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function sprintf;
use function str_ends_with;
use function strtr;
use function strval;

use const FILTER_VALIDATE_INT;

// The prefix is the web_ui.path_prefix container parameter, resolved by the Symfony router (any import style).
#[Route('%nowo_http_log.web_ui.path_prefix%')]
final class HttpLogAdminController extends AbstractController
{
    /** @var list<string> */
    private const CRITERIA_FIELDS = [
        'method',
        'routeName',
        'statusCode',
        'clientIp',
        'path',
        'bodyContentType',
        'q',
        'createdFrom',
        'createdTo',
        // HL-1 usage filters (ignored by the repository while the usage columns are not mapped).
        'channel',
        'provider',
        'tenantKey',
        'projectKey',
        'credentialKind',
    ];

    public function __construct(
        private readonly HttpLogEntryRepository $repository,
        private readonly HttpLogAccessCheckerInterface $accessChecker,
        private readonly ExportHttpLogService $exportService,
        private readonly PurgeHttpLogService $purgeService,
        private readonly MessageBusInterface $messageBus,
        private readonly bool $allowUnauthenticated,
        private readonly int $maxSyncExportRows,
        private readonly int $pageSize,
        /** @var array<string, mixed> Processed `web_ui` configuration (layout_template, templates, deprecated attributes). */
        private readonly array $webUi = [],
        private readonly ?TranslatorInterface $translator = null,
        /** @var list<string> Allowed export formats (export.formats). */
        private readonly array $exportFormats = ['csv', 'json'],
        // REQ-SF-003 / REQ-DI-001: injected (autowired router) instead of $this->container->get('router').
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        // Private storage of background exports (export.directory / export.ttl_hours).
        private readonly ?ExportFileStore $exportStore = null,
        /** @var list<string> HL-1 usage channels enabled (outbound, mailer); empty = usage columns not mapped. */
        private readonly array $usageChannels = [],
    ) {
    }

    #[Route('', name: 'nowo_http_log_admin_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessGrantedAdmin();

        $filterForm = $this->createForm(HttpLogFilterType::class, null, $this->usageChannels === [] ? [] : [
            // only the channels this user may see (IX-I5)
            'usage_channels' => $this->allowedChannels() ?? [HttpLogChannel::WEB, ...$this->usageChannels],
        ]);
        $filterForm->handleRequest($request);

        $criteria = $filterForm->isSubmitted() && $filterForm->isValid()
            ? $this->normalizeCriteria($filterForm->getData())
            : [];

        $criteriaParams = $this->queryParamsFromCriteria($criteria);
        $page           = max(1, $request->query->getInt('page', 1));
        // Bounded page + count (HttpLogEntryRepository::MAX_LISTED): no full COUNT / deep OFFSET on a large log.
        $result = $this->repository->findListPage($this->withChannelAccess($criteria), $page, $this->pageSize);
        $page   = $result['page'];

        $exportForms = [];
        foreach ([ExportFormat::Csv, ExportFormat::Json] as $format) {
            $exportForm = $this->exportFormView($format, $criteriaParams);
            if ($exportForm instanceof FormView) {
                $exportForms[$format->value] = $exportForm;
            }
        }

        $response = $this->render($this->template('index'), [
            'page' => $this->pages()->listPage(
                $result['items'],
                $result['total'],
                $page,
                $this->pageSize,
                $filterForm->createView(),
                $exportForms,
                $this->createForm(HttpLogPurgeType::class, null, [
                    'action' => $this->generateUrl('nowo_http_log_admin_purge'),
                ])->createView(),
                $criteria !== [],
                $this->canExport(),
                $this->canPurge(),
                $this->canExport() ? $this->readyExports() : [],
                $result['total_capped'],
            ),
            'criteria' => $criteria,
        ]);
        $this->repository->detachAll($result['items']);

        return $response;
    }

    #[Route('/{id}', name: 'nowo_http_log_admin_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $this->denyUnlessGrantedAdmin();

        $entry = $this->repository->find($id);
        if (!$entry instanceof HttpLogEntry || !$this->canViewEntry($entry)) {
            // a denied channel gives the same 404 as a missing entry
            throw $this->createNotFoundException();
        }

        $deleteForm = $this->createForm(HttpLogDeleteType::class, null, [
            'action' => $this->generateUrl('nowo_http_log_admin_delete', ['id' => $entry->getId()]),
        ])->createView();

        $pages    = $this->pages();
        $response = $this->render($this->template('show'), [
            'page'    => $pages->showPage($entry, $deleteForm, $this->canPurge()),
            'entry'   => $entry,
            'payload' => $pages->payload($entry),
        ]);
        $this->repository->detachAll([$entry]);

        return $response;
    }

    /** View-model builder of the admin pages (per call: no state kept between requests). */
    private function pages(): AdminPages
    {
        if (!$this->urlGenerator instanceof UrlGeneratorInterface) {
            throw new LogicException('HttpLogAdminController needs a UrlGeneratorInterface (autowired from the router).');
        }

        return new AdminPages($this->urlGenerator, $this->webUi, $this->usageChannels !== []);
    }

    #[Route('/export', name: 'nowo_http_log_admin_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->denyUnlessGrantedExport();

        $data = $this->validateActionForm($request, HttpLogExportType::class, [
            'criteria' => $this->rawCriteriaFromRequest($request),
            'format'   => (string) $request->request->get('format', ExportFormat::Csv->value),
        ]);
        $formatValue = (string) ($data['format'] ?? ExportFormat::Csv->value);
        $format      = ExportFormat::tryFrom($formatValue) ?? ExportFormat::Csv;
        if (!$this->isExportFormatAllowed($format)) {
            throw new BadRequestHttpException(sprintf('Export format "%s" is not enabled (nowo_http_log.export.formats).', $format->value));
        }
        $criteria = $this->withChannelAccess($this->criteriaFromRequest($request));

        $count = $this->exportService->countForCriteria($criteria);
        if ($count > $this->maxSyncExportRows) {
            $store = $this->exportStore();
            // the store is shared: the file remembers the channels it holds (IX-M1)
            $name = $store->reserve($format, $this->allowedChannels());
            $this->messageBus->dispatch(new ExportHttpLogMessage($criteria, $format->value, $store->partialPath($name), $name));
            $this->addFlash('success', $this->translate('flash.export_queued', 'Export queued for background processing. When it finishes, download it from the list header (kept for a limited time).'));

            return $this->redirectToRoute('nowo_http_log_admin_index', $this->queryParamsFromCriteria($criteria));
        }

        $content  = $this->exportService->exportToString($criteria, $format);
        $filename = sprintf('http-log-export.%s', $format->value);
        $mimeType = $format === ExportFormat::Json ? 'application/json' : 'text/csv';

        return new Response($content, Response::HTTP_OK, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    #[Route('/export/{name}', name: 'nowo_http_log_admin_export_download', requirements: ['name' => '[a-f0-9]{32}\\.(csv|json)'], methods: ['GET'])]
    public function downloadExport(string $name): BinaryFileResponse
    {
        $this->denyUnlessGrantedExport();

        $path = $this->exportStore()->readyPath($name, $this->allowedChannels());
        if ($path === null) {
            throw $this->createNotFoundException();
        }
        $format   = str_ends_with($name, '.json') ? ExportFormat::Json : ExportFormat::Csv;
        $response = new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type'  => $format === ExportFormat::Json ? 'application/json' : 'text/csv',
            'Cache-Control' => 'private, no-store',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('http-log-export.%s', $format->value));

        return $response;
    }

    /**
     * Finished background exports offered for download; expired ones are deleted here as well as after each export
     * job, so files do not outlive export.ttl_hours when no new export runs.
     *
     * @return list<array{name: string, format: ExportFormat, size: int, createdAt: DateTimeImmutable}>
     */
    private function readyExports(): array
    {
        if (!$this->exportStore instanceof ExportFileStore) {
            return [];
        }
        $this->exportStore->purgeExpired();

        return $this->exportStore->ready(viewerChannels: $this->allowedChannels());
    }

    private function exportStore(): ExportFileStore
    {
        if (!$this->exportStore instanceof ExportFileStore) {
            throw new LogicException('HttpLogAdminController needs an ExportFileStore (registered by the bundle) for background exports.');
        }

        return $this->exportStore;
    }

    private function isExportFormatAllowed(ExportFormat $format): bool
    {
        return in_array($format->value, $this->exportFormats, true);
    }

    /**
     * @param array<string, mixed> $criteriaParams
     */
    private function exportFormView(ExportFormat $format, array $criteriaParams): ?FormView
    {
        if (!$this->isExportFormatAllowed($format)) {
            return null;
        }

        return $this->createForm(HttpLogExportType::class, null, [
            'action'   => $this->generateUrl('nowo_http_log_admin_export'),
            'criteria' => $criteriaParams,
            'format'   => $format->value,
        ])->createView();
    }

    #[Route('/purge', name: 'nowo_http_log_admin_purge', methods: ['POST'])]
    public function purge(Request $request): RedirectResponse
    {
        $this->denyUnlessGrantedPurge();
        $this->validateActionForm($request, HttpLogPurgeType::class);

        $purgeAll = $request->request->getBoolean('purge_all');
        $days     = $request->request->get('older_than_days');

        $allowed = $this->allowedChannels();
        if ($purgeAll) {
            $criteria = $this->withChannelAccess($this->criteriaFromRequest($request));
            $purged   = $this->purgeService->purgeByCriteria($criteria);
        } elseif ($days !== null && $days !== '') {
            $days   = $this->parseRetentionDays($days);
            $purged = $allowed === null
                ? $this->purgeService->purgeByRetention($days)
                : $this->purgeService->purgeByCriteria(['createdBefore' => $this->purgeService->cutoff($days), 'allowedChannels' => $allowed]);
        } elseif ($allowed === null) {
            // per-call rows only: the admin form never purges the rollup
            $purged = $this->purgeService->purgeEntriesByRetention();
        } else {
            // retention per scope, only for the channels this user may see
            $scopes = array_values(array_intersect($this->purgeService->entryScopes(), $allowed));
            $purged = $scopes === [] ? 0 : array_sum($this->purgeService->purgeScopes($scopes));
        }

        $this->addFlash('success', $this->translate('flash.purged', 'Purged %count% HTTP log entries.', ['%count%' => $purged]));

        return $this->redirectToRoute('nowo_http_log_admin_index');
    }

    /**
     * older_than_days must be a non-negative integer: "-5" would become "--5 days" (a future cutoff).
     */
    private function parseRetentionDays(mixed $days): int
    {
        $parsed = is_string($days) || is_int($days) ? filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($parsed === false) {
            throw new BadRequestHttpException('older_than_days must be a non-negative integer.');
        }

        return $parsed;
    }

    #[Route('/{id}/delete', name: 'nowo_http_log_admin_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessGrantedPurge();
        $this->validateActionForm($request, HttpLogDeleteType::class);

        if ($this->allowedChannels() !== null) {
            $entry = $this->repository->find($id);
            if ($entry instanceof HttpLogEntry) {
                $this->repository->detachAll([$entry]);
                if (!$this->canViewEntry($entry)) {
                    throw $this->createNotFoundException();
                }
            }
        }

        $this->repository->deleteByIds([$id]);
        $this->addFlash('success', $this->translate('flash.deleted', 'HTTP log entry deleted.'));

        return $this->redirectToRoute('nowo_http_log_admin_index');
    }

    /**
     * Page template from web_ui.templates.<page> (index, show), falling back to the bundle default.
     */
    private function template(string $name): string
    {
        $templates = $this->webUi['templates'] ?? [];
        $template  = is_array($templates) ? ($templates[$name] ?? null) : null;

        return is_string($template) && $template !== '' ? $template : Configuration::PAGE_TEMPLATES[$name];
    }

    /**
     * Flash messages use the NowoHttpLogBundle catalogue; the English fallback keeps apps without a translator working.
     *
     * @param array<string, int|string> $parameters
     */
    private function translate(string $key, string $fallback, array $parameters = []): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return strtr($fallback, array_map(strval(...), $parameters));
        }

        return $this->translator->trans($key, $parameters, 'NowoHttpLogBundle');
    }

    /**
     * Channels the configured access checker lets the current user see (HL-1 §11.2), or null when it does not
     * implement {@see HttpLogChannelAccessCheckerInterface} (no channel restriction, the 1.14 behaviour).
     *
     * @return list<string>|null
     */
    private function allowedChannels(): ?array
    {
        if ($this->allowUnauthenticated || !$this->accessChecker instanceof HttpLogChannelAccessCheckerInterface) {
            return null;
        }

        $user    = $this->getUser();
        $allowed = [];
        foreach ([HttpLogChannel::WEB, ...$this->usageChannels] as $channel) {
            if ($this->accessChecker->canViewChannel($user, $channel)) {
                $allowed[] = $channel;
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    private function withChannelAccess(array $criteria): array
    {
        $allowed = $this->allowedChannels();
        if ($allowed !== null) {
            $criteria['allowedChannels'] = $allowed;
        }

        return $criteria;
    }

    private function canViewEntry(HttpLogEntry $entry): bool
    {
        $allowed = $this->allowedChannels();

        return $allowed === null || in_array($entry->getChannel() ?? HttpLogChannel::WEB, $allowed, true);
    }

    /** Export actions are shown only to users who may export (the export route enforces it anyway). */
    private function canExport(): bool
    {
        return $this->allowUnauthenticated || $this->accessChecker->canExport($this->getUser());
    }

    /** Purge / delete actions are shown only to users who may purge (the routes enforce it anyway). */
    private function canPurge(): bool
    {
        return $this->allowUnauthenticated || $this->accessChecker->canPurge($this->getUser());
    }

    private function denyUnlessGrantedAdmin(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        if (!$this->accessChecker->canAccessAdmin($this->getUser())) {
            throw new AccessDeniedException('Access to HTTP log admin is denied.');
        }
    }

    private function denyUnlessGrantedExport(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        if (!$this->accessChecker->canExport($this->getUser())) {
            throw new AccessDeniedException('HTTP log export is denied.');
        }
    }

    private function denyUnlessGrantedPurge(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        if (!$this->accessChecker->canPurge($this->getUser())) {
            throw new AccessDeniedException('HTTP log purge is denied.');
        }
    }

    /**
     * @param class-string<AbstractHttpLogActionType> $type
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function validateActionForm(Request $request, string $type, array $options = []): array
    {
        $form = $this->createForm($type, null, $options);
        $form->handleRequest($request);

        if (!$form->isSubmitted() && $request->isMethod('POST')) {
            $form->submit($request->request->all());
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        return $this->normalizeCriteria($form->getData());
    }

    /** @return array<string, mixed> */
    private function criteriaFromRequest(Request $request): array
    {
        $criteria = [];
        foreach (self::CRITERIA_FIELDS as $key) {
            $value = $request->request->get($key);
            if ($value !== null && $value !== '') {
                if (in_array($key, ['createdFrom', 'createdTo'], true)) {
                    $criteria[$key] = $this->parseDateCriteria($value);
                } else {
                    $criteria[$key] = $value;
                }
            }
        }

        return $this->normalizeCriteria($criteria);
    }

    /** @return array<string, mixed> */
    private function rawCriteriaFromRequest(Request $request): array
    {
        $criteria = [];
        foreach (self::CRITERIA_FIELDS as $key) {
            $value = $request->request->get($key);
            if ($value !== null && $value !== '') {
                $criteria[$key] = $value;
            }
        }

        return $criteria;
    }

    /**
     * @param array<string, mixed>|mixed $data
     *
     * @return array<string, mixed>
     */
    private function normalizeCriteria(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $criteria = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $criteria[$key] = $value;
        }

        return $criteria;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, bool|float|int|string>
     */
    private function queryParamsFromCriteria(array $criteria): array
    {
        $params = [];
        foreach ($criteria as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $params[$key] = $value->format('Y-m-d\\TH:i');
            } elseif (is_scalar($value)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function parseDateCriteria(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return $value;
        }
    }
}
