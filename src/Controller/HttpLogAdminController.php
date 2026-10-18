<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
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
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\View\AdminPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function sprintf;
use function strtr;
use function strval;
use function sys_get_temp_dir;
use function uniqid;

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
    ) {
    }

    #[Route('', name: 'nowo_http_log_admin_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessGrantedAdmin();

        $filterForm = $this->createForm(HttpLogFilterType::class);
        $filterForm->handleRequest($request);

        $criteria = $filterForm->isSubmitted() && $filterForm->isValid()
            ? $this->normalizeCriteria($filterForm->getData())
            : [];

        $criteriaParams = $this->queryParamsFromCriteria($criteria);
        $page           = max(1, $request->query->getInt('page', 1));
        $result         = $this->repository->findFiltered($criteria, $page, $this->pageSize);

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
        if (!$entry instanceof HttpLogEntry) {
            throw $this->createNotFoundException();
        }

        $deleteForm = $this->createForm(HttpLogDeleteType::class, null, [
            'action' => $this->generateUrl('nowo_http_log_admin_delete', ['id' => $entry->getId()]),
        ])->createView();

        $response = $this->render($this->template('show'), [
            'page'  => $this->pages()->showPage($entry, $deleteForm, $this->canPurge()),
            'entry' => $entry,
        ]);
        $this->repository->detachAll([$entry]);

        return $response;
    }

    /** View-model builder of the admin pages (per call: no state kept between requests). */
    private function pages(): AdminPages
    {
        /** @var UrlGeneratorInterface $router */
        $router = $this->container->get('router');

        return new AdminPages($router, $this->webUi);
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
        $criteria = $this->criteriaFromRequest($request);

        $count = $this->exportService->countForCriteria($criteria);
        if ($count > $this->maxSyncExportRows) {
            $outputPath = sprintf('%s/http-log-export-%s.%s', sys_get_temp_dir(), uniqid('', true), $format->value);
            $this->messageBus->dispatch(new ExportHttpLogMessage($criteria, $format->value, $outputPath));
            $this->addFlash('success', $this->translate('flash.export_queued', 'Export queued for background processing.'));

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

        if ($purgeAll) {
            $criteria = $this->criteriaFromRequest($request);
            $purged   = $this->purgeService->purgeByCriteria($criteria);
        } elseif ($days !== null && $days !== '') {
            $purged = $this->purgeService->purgeByRetention($this->parseRetentionDays($days));
        } else {
            $purged = $this->purgeService->purgeByRetention();
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
