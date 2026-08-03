<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class HttpLogAdminControllerTest extends TestCase
{
    #[Test]
    public function indexRendersEntriesWhenAccessIsAllowed(): void
    {
        $request = Request::create('/admin/http-log?page=2', 'GET');
        $entry   = $this->createEntry(10);

        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(false);
        $form->expects(self::once())->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects(self::once())->method('create')->willReturn($form);

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], 2, 20)
            ->willReturn(['items' => [$entry], 'total' => 1]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoHttpLogBundle/admin/index.html.twig',
                self::callback(static fn (array $parameters): bool => $parameters['entries'] === [$entry]
                    && $parameters['total'] === 1
                    && $parameters['page'] === 2
                    && $parameters['pageSize'] === 20
                    && $parameters['criteria'] === []),
            )
            ->willReturn('index');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $formFactory,
            twig: $twig,
            allowUnauthenticated: true,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function indexNormalizesSubmittedNonArrayDataToEmptyCriteria(): void
    {
        $request = Request::create('/admin/http-log', 'GET');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(true);
        $form->expects(self::once())->method('isValid')->willReturn(true);
        $form->expects(self::once())->method('getData')->willReturn('invalid');
        $form->expects(self::once())->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with([], 1, 20)
            ->willReturn(['items' => [], 'total' => 0]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('index');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $formFactory,
            twig: $twig,
            allowUnauthenticated: true,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function indexDropsBlankValuesFromSubmittedCriteria(): void
    {
        $request = Request::create('/admin/http-log', 'GET');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(true);
        $form->expects(self::once())->method('isValid')->willReturn(true);
        $form->expects(self::once())->method('getData')->willReturn([
            'method'     => '',
            'routeName'  => null,
            'statusCode' => 200,
        ]);
        $form->expects(self::once())->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('findFiltered')
            ->with(['statusCode' => 200], 1, 20)
            ->willReturn(['items' => [], 'total' => 0]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('index');

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            formFactory: $formFactory,
            twig: $twig,
            allowUnauthenticated: true,
        );

        $response = $controller->index($request);

        self::assertSame('index', $response->getContent());
    }

    #[Test]
    public function showRendersExistingEntry(): void
    {
        $entry      = $this->createEntry(7);
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())->method('find')->with(7)->willReturn($entry);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with('@NowoHttpLogBundle/admin/show.html.twig', ['entry' => $entry])
            ->willReturn('show');

        $controller = $this->createController(
            repository: $repository,
            twig: $twig,
            allowUnauthenticated: true,
        );

        $response = $controller->show(7);

        self::assertSame('show', $response->getContent());
    }

    #[Test]
    public function showThrowsNotFoundWhenEntryDoesNotExist(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())->method('find')->with(99)->willReturn(null);

        $controller = $this->createController(
            repository: $repository,
            allowUnauthenticated: true,
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->show(99);
    }

    #[Test]
    public function exportReturnsDownloadResponseWhenBelowSyncThreshold(): void
    {
        $request = Request::create('/admin/http-log/export', 'POST', [
            '_token'    => 'valid-token',
            'format'    => 'json',
            'method'    => 'POST',
            'routeName' => 'admin_logs',
        ]);

        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('countForCriteria')
            ->with(['method' => 'POST', 'routeName' => 'admin_logs'])
            ->willReturn(2);
        $exportService->expects(self::once())
            ->method('exportToString')
            ->willReturn('{"rows":2}');

        $controller = $this->createController(
            request: $request,
            exportService: $exportService,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $response = $controller->export($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="http-log-export.json"', $response->headers->get('Content-Disposition'));
        self::assertSame('{"rows":2}', $response->getContent());
    }

    #[Test]
    public function exportQueuesBackgroundJobWhenResultExceedsSyncThreshold(): void
    {
        $request = Request::create('/admin/http-log/export', 'POST', [
            '_token'      => 'valid-token',
            'format'      => 'json',
            'method'      => 'GET',
            'createdFrom' => '2026-08-03T10:15',
            'createdTo'   => '2026-08-03T11:45',
        ]);

        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('countForCriteria')
            ->with(self::callback(static fn (array $criteria): bool => ($criteria['method'] ?? null) === 'GET'
                && ($criteria['createdFrom'] ?? null) instanceof DateTimeImmutable
                && ($criteria['createdTo'] ?? null) instanceof DateTimeImmutable))
            ->willReturn(50);
        $exportService->expects(self::never())->method('exportToString');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ExportHttpLogMessage $message): bool => $message->format === 'json'
                && ($message->criteria['createdFrom'] ?? null) instanceof DateTimeImmutable
                && ($message->criteria['createdTo'] ?? null) instanceof DateTimeImmutable
                && str_ends_with($message->outputPath, '.json')))
            ->willReturn(new Envelope(new stdClass()));

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'nowo_http_log_admin_index',
                [
                    'method'      => 'GET',
                    'createdFrom' => '2026-08-03T10:15',
                    'createdTo'   => '2026-08-03T11:45',
                ],
                1,
            )
            ->willReturn('/admin/http-log?queued=1');

        $controller = $this->createController(
            request: $request,
            exportService: $exportService,
            messageBus: $messageBus,
            router: $router,
            allowUnauthenticated: true,
            csrfValid: true,
            maxSyncExportRows: 10,
        );

        $response = $controller->export($request);

        self::assertSame('/admin/http-log?queued=1', $response->headers->get('Location'));
        self::assertSame(['Export queued for background processing.'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function purgeAllUsesCriteriaFromRequest(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', [
            '_token'      => 'valid-token',
            'purge_all'   => '1',
            'method'      => 'POST',
            'createdFrom' => 'not-a-date',
        ]);

        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())
            ->method('purgeByCriteria')
            ->with(['method' => 'POST', 'createdFrom' => 'not-a-date'])
            ->willReturn(3);

        $controller = $this->createController(
            request: $request,
            purgeService: $purgeService,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $response = $controller->purge($request);

        self::assertSame('/admin/http-log', $response->headers->get('Location'));
        self::assertSame(['Purged 3 HTTP log entries.'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function purgeAllPreservesNonStringDateCriteriaValues(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', [
            '_token'    => 'valid-token',
            'purge_all' => '1',
        ]);
        $request->request->set('createdFrom', 123);

        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())
            ->method('purgeByCriteria')
            ->with(['createdFrom' => 123])
            ->willReturn(1);

        $controller = $this->createController(
            request: $request,
            purgeService: $purgeService,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $controller->purge($request);
    }

    #[Test]
    public function purgeUsesRequestedRetentionDays(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', [
            '_token'          => 'valid-token',
            'older_than_days' => '14',
        ]);

        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())->method('purgeByRetention')->with(14)->willReturn(4);

        $controller = $this->createController(
            request: $request,
            purgeService: $purgeService,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $response = $controller->purge($request);

        self::assertSame('/admin/http-log', $response->headers->get('Location'));
    }

    #[Test]
    public function purgeUsesDefaultRetentionWhenNoDaysProvided(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST', [
            '_token' => 'valid-token',
        ]);

        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())->method('purgeByRetention')->with(null)->willReturn(2);

        $controller = $this->createController(
            request: $request,
            purgeService: $purgeService,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $response = $controller->purge($request);

        self::assertSame('/admin/http-log', $response->headers->get('Location'));
    }

    #[Test]
    public function deleteRemovesEntryAndRedirects(): void
    {
        $request = Request::create('/admin/http-log/5/delete', 'POST', [
            '_token' => 'valid-token',
        ]);

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())->method('deleteByIds')->with([5])->willReturn(1);

        $controller = $this->createController(
            request: $request,
            repository: $repository,
            allowUnauthenticated: true,
            csrfValid: true,
        );

        $response = $controller->delete(5, $request);

        self::assertSame('/admin/http-log', $response->headers->get('Location'));
        self::assertSame(['HTTP log entry deleted.'], $request->getSession()->getFlashBag()->get('success'));
    }

    #[Test]
    public function indexDeniesAccessWhenAdminCheckerFails(): void
    {
        $request = Request::create('/admin/http-log', 'GET');

        $accessChecker = $this->createMock(HttpLogAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canAccessAdmin')->with(null)->willReturn(false);

        $controller = $this->createController(
            request: $request,
            accessChecker: $accessChecker,
            allowUnauthenticated: false,
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access to HTTP log admin is denied.');

        $controller->index($request);
    }

    #[Test]
    public function exportDeniesAccessWhenExportCheckerFails(): void
    {
        $request = Request::create('/admin/http-log/export', 'POST');

        $accessChecker = $this->createMock(HttpLogAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canExport')->with(null)->willReturn(false);

        $controller = $this->createController(
            request: $request,
            accessChecker: $accessChecker,
            allowUnauthenticated: false,
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('HTTP log export is denied.');

        $controller->export($request);
    }

    #[Test]
    public function purgeDeniesAccessWhenPurgeCheckerFails(): void
    {
        $request = Request::create('/admin/http-log/purge', 'POST');

        $accessChecker = $this->createMock(HttpLogAccessCheckerInterface::class);
        $accessChecker->expects(self::once())->method('canPurge')->with(null)->willReturn(false);

        $controller = $this->createController(
            request: $request,
            accessChecker: $accessChecker,
            allowUnauthenticated: false,
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('HTTP log purge is denied.');

        $controller->purge($request);
    }

    #[Test]
    public function deleteRejectsInvalidCsrfToken(): void
    {
        $request = Request::create('/admin/http-log/8/delete', 'POST', [
            '_token' => 'invalid-token',
        ]);

        $controller = $this->createController(
            request: $request,
            allowUnauthenticated: true,
            csrfValid: false,
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $controller->delete(8, $request);
    }

    private function createController(
        ?Request $request = null,
        ?HttpLogEntryRepository $repository = null,
        ?HttpLogAccessCheckerInterface $accessChecker = null,
        ?ExportHttpLogService $exportService = null,
        ?PurgeHttpLogService $purgeService = null,
        ?MessageBusInterface $messageBus = null,
        ?FormFactoryInterface $formFactory = null,
        ?Environment $twig = null,
        ?RouterInterface $router = null,
        bool $allowUnauthenticated = true,
        bool $csrfValid = true,
        int $maxSyncExportRows = 10,
        int $pageSize = 20,
    ): HttpLogAdminController {
        $request ??= Request::create('/admin/http-log', 'GET');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn($csrfValid);

        $container = new ContainerBuilder();
        $container->set('request_stack', $requestStack);
        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('form.factory', $formFactory ?? $this->createMock(FormFactoryInterface::class));
        $container->set('twig', $twig ?? $this->createMock(Environment::class));
        $container->set('router', $router ?? $this->createConfiguredMock(RouterInterface::class, [
            'generate' => '/admin/http-log',
        ]));

        $controller = new HttpLogAdminController(
            $repository ?? $this->createMock(HttpLogEntryRepository::class),
            $accessChecker ?? $this->createMock(HttpLogAccessCheckerInterface::class),
            $exportService ?? $this->createMock(ExportHttpLogService::class),
            $purgeService ?? $this->createMock(PurgeHttpLogService::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $allowUnauthenticated,
            $maxSyncExportRows,
            $pageSize,
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function createEntry(int $id): HttpLogEntry
    {
        $entry = (new HttpLogEntry())
            ->setMethod('GET')
            ->setPath('/admin/http-log')
            ->setCreatedAt(new DateTimeImmutable('2026-08-03T10:00:00+00:00'));

        $reflection = new ReflectionProperty(HttpLogEntry::class, 'id');
        $reflection->setValue($entry, $id);

        return $entry;
    }
}
