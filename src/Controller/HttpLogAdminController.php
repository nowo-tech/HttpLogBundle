<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function sys_get_temp_dir;

#[Route('/admin/http-log')]
final class HttpLogAdminController extends AbstractController
{
    public function __construct(
        private readonly HttpLogEntryRepository $repository,
        private readonly HttpLogAccessCheckerInterface $accessChecker,
        private readonly ExportHttpLogService $exportService,
        private readonly PurgeHttpLogService $purgeService,
        private readonly MessageBusInterface $messageBus,
        private readonly bool $allowUnauthenticated,
        private readonly int $maxSyncExportRows,
        private readonly int $pageSize,
    ) {
    }

    #[Route('', name: 'nowo_http_log_admin_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessGrantedAdmin();

        $form = $this->createForm(HttpLogFilterType::class);
        $form->handleRequest($request);

        $criteria = $form->isSubmitted() && $form->isValid()
            ? $this->normalizeCriteria($form->getData())
            : [];

        $page   = max(1, $request->query->getInt('page', 1));
        $result = $this->repository->findFiltered($criteria, $page, $this->pageSize);

        return $this->render('@NowoHttpLogBundle/admin/index.html.twig', [
            'filterForm' => $form->createView(),
            'entries'    => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'pageSize'   => $this->pageSize,
            'criteria'   => $criteria,
        ]);
    }

    #[Route('/{id}', name: 'nowo_http_log_admin_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $this->denyUnlessGrantedAdmin();

        $entry = $this->repository->find($id);
        if (!$entry instanceof HttpLogEntry) {
            throw $this->createNotFoundException();
        }

        return $this->render('@NowoHttpLogBundle/admin/show.html.twig', [
            'entry' => $entry,
        ]);
    }

    #[Route('/export', name: 'nowo_http_log_admin_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->denyUnlessGrantedExport();
        $this->validateCsrf($request, 'http_log_export');

        $formatValue = (string) $request->request->get('format', ExportFormat::Csv->value);
        $format      = ExportFormat::tryFrom($formatValue) ?? ExportFormat::Csv;
        $criteria    = $this->criteriaFromRequest($request);

        $count = $this->exportService->countForCriteria($criteria);
        if ($count > $this->maxSyncExportRows) {
            $outputPath = sprintf('%s/http-log-export-%s.%s', sys_get_temp_dir(), uniqid('', true), $format->value);
            $this->messageBus->dispatch(new ExportHttpLogMessage($criteria, $format->value, $outputPath));
            $this->addFlash('success', 'Export queued for background processing.');

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

    #[Route('/purge', name: 'nowo_http_log_admin_purge', methods: ['POST'])]
    public function purge(Request $request): RedirectResponse
    {
        $this->denyUnlessGrantedPurge();
        $this->validateCsrf($request, 'http_log_purge');

        $purgeAll = $request->request->getBoolean('purge_all');
        $days     = $request->request->get('older_than_days');

        if ($purgeAll) {
            $criteria = $this->criteriaFromRequest($request);
            $purged   = $this->purgeService->purgeByCriteria($criteria);
        } elseif ($days !== null && $days !== '') {
            $purged = $this->purgeService->purgeByRetention((int) $days);
        } else {
            $purged = $this->purgeService->purgeByRetention();
        }

        $this->addFlash('success', sprintf('Purged %d HTTP log entries.', $purged));

        return $this->redirectToRoute('nowo_http_log_admin_index');
    }

    #[Route('/{id}/delete', name: 'nowo_http_log_admin_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessGrantedPurge();
        $this->validateCsrf($request, 'http_log_delete');

        $this->repository->deleteByIds([$id]);
        $this->addFlash('success', 'HTTP log entry deleted.');

        return $this->redirectToRoute('nowo_http_log_admin_index');
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

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @return array<string, mixed> */
    private function criteriaFromRequest(Request $request): array
    {
        $criteria = [];
        foreach (['method', 'routeName', 'statusCode', 'clientIp', 'path', 'bodyContentType', 'q', 'createdFrom', 'createdTo'] as $key) {
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
                $params[$key] = $value->format('Y-m-d\TH:i');
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
