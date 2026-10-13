<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Form\HttpLogDeleteType;
use Nowo\HttpLogBundle\Form\HttpLogExportType;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;

/**
 * Builds a real Twig environment (forms, routing, translations, UiKit macros) for admin template render tests.
 */
final class AdminTwigHarness
{
    public const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    /**
     * Form theme that renders every form_row as label-only stub, so golden files pin the bundle chrome and not
     * the widget markup owned by Symfony Form / FormKit (asserted separately as invariants).
     */
    public const GOLDEN_FORM_ROW_THEME = 'golden_form_rows.html.twig';

    private readonly FormFactoryInterface $formFactory;

    private readonly Translator $translator;

    public function __construct()
    {
        $this->translator = new Translator('en');
        $this->translator->addLoader('yaml', new YamlFileLoader());
        $this->translator->addResource('yaml', dirname(__DIR__, 2) . '/src/Resources/translations/NowoHttpLogBundle.en.yaml', 'en', 'NowoHttpLogBundle');

        $filterType = new HttpLogFilterType();
        // Mirrors the http_log profile seeded by NowoHttpLogExtension::prependFormKitDefaults().
        $filterType->setFormOptionsMerger(new FormOptionsMerger(
            [
                'http_log' => [
                    'translation_domain'              => 'NowoHttpLogBundle',
                    'convention_requires_translation' => true,
                    'defaults'                        => ['attr' => [], 'row_attr' => []],
                    'field_types'                     => [],
                ],
            ],
            'http_log',
            new ConstraintDefinitionFactory(),
            translator: $this->translator,
        ));

        $this->formFactory = (new FormFactoryBuilder())
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($this->csrfTokenManager()))
            ->addType($filterType)
            ->getFormFactory();
    }

    /** Real form factory (HttpFoundation + CSRF, FormKit-backed filter type) shared with the controller in functional tests. */
    public function formFactory(): FormFactoryInterface
    {
        return $this->formFactory;
    }

    /**
     * @param array<string, mixed> $globals
     */
    public function createEnvironment(array $globals = [], bool $stubFormRows = false): Environment
    {
        $root   = dirname(__DIR__, 2);
        $views  = $root . '/src/Resources/views';
        $loader = new FilesystemLoader();
        $loader->addPath(self::FIXTURES_DIR . '/templates');
        $loader->addPath($views, 'NowoHttpLogBundle');
        $loader->addPath($views, '!NowoHttpLogBundle');
        $loader->addPath($root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');
        $formExtensionFile = (new ReflectionClass(FormExtension::class))->getFileName();
        $loader->addPath(dirname((string) $formExtensionFile, 2) . '/Resources/views/Form');

        $twig = new Environment($loader, ['cache' => false, 'strict_variables' => false, 'autoescape' => 'html']);

        $twig->addExtension(new TranslationExtension($this->translator));

        $routes = new RouteCollection();
        $routes->add('nowo_http_log_admin_index', new Route('/admin/http-log'));
        $routes->add('nowo_http_log_admin_show', new Route('/admin/http-log/{id}'));
        $routes->add('nowo_http_log_admin_export', new Route('/admin/http-log/export'));
        $routes->add('nowo_http_log_admin_purge', new Route('/admin/http-log/purge'));
        $routes->add('nowo_http_log_admin_delete', new Route('/admin/http-log/{id}/delete'));
        $twig->addExtension(new RoutingExtension(new UrlGenerator($routes, new RequestContext())));

        $twig->addExtension(new AssetExtension(new Packages(
            new PathPackage('/', new EmptyVersionStrategy()),
            ['nowo_ui_kit' => new PathPackage('/bundles/nowouikit', new EmptyVersionStrategy())],
        )));

        $twig->addExtension(new FormExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine($stubFormRows ? ['form_div_layout.html.twig', self::GOLDEN_FORM_ROW_THEME] : ['form_div_layout.html.twig'], $twig)),
        ]));

        $request = Request::create('/admin/http-log');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $app = new AppVariable();
        $app->setRequestStack($requestStack);
        $twig->addGlobal('app', $app);

        foreach ($globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }

        return $twig;
    }

    /**
     * Context the controller passes to admin/index.html.twig.
     *
     * @param list<HttpLogEntry> $entries
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function indexContext(array $entries, int $total, int $page = 1, int $pageSize = 2, array $extra = []): array
    {
        return [
            'filterForm'     => $this->formFactory->create(HttpLogFilterType::class)->createView(),
            'exportCsvForm'  => $this->formFactory->create(HttpLogExportType::class, null, ['action' => '/admin/http-log/export', 'format' => 'csv'])->createView(),
            'exportJsonForm' => $this->formFactory->create(HttpLogExportType::class, null, ['action' => '/admin/http-log/export', 'format' => 'json'])->createView(),
            'purgeForm'      => $this->formFactory->create(HttpLogPurgeType::class, null, ['action' => '/admin/http-log/purge'])->createView(),
            'entries'        => $entries,
            'total'          => $total,
            'page'           => $page,
            'pageSize'       => $pageSize,
            'criteria'       => ['method' => 'GET', 'createdFrom' => new DateTimeImmutable('2026-08-01T09:30:00+00:00')],
        ] + $extra;
    }

    /**
     * Context the controller passes to admin/show.html.twig.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function showContext(HttpLogEntry $entry, array $extra = []): array
    {
        return [
            'entry'      => $entry,
            'deleteForm' => $this->formFactory->create(HttpLogDeleteType::class, null, ['action' => '/admin/http-log/' . $entry->getId() . '/delete'])->createView(),
        ] + $extra;
    }

    public static function entry(int $id, bool $full = false): HttpLogEntry
    {
        $entry = (new HttpLogEntry())
            ->setRequestId('req-' . $id)
            ->setMethod('POST')
            ->setScheme('https')
            ->setHost('example.test')
            ->setPath('/api/items/' . $id)
            ->setRouteName('app_items')
            ->setStatusCode(201)
            ->setClientIp('127.0.0.1')
            ->setContentType('application/json')
            ->setBodyContentType('json')
            ->setDurationMs(12.345)
            ->setUserIdentifier('admin@example.test')
            ->setCreatedAt(new DateTimeImmutable('2026-08-03T10:00:00+00:00'));

        if ($full) {
            $entry
                ->setQueryParams(['page' => '1'])
                ->setRequestHeaders(['accept' => ['application/json']])
                ->setResponseHeaders(['content-type' => ['application/json']])
                ->setRequestBody('{"name":"x"}')
                ->setRequestBodyTruncated(true)
                ->setResponseBody('{"id":1}');
        }

        (new ReflectionProperty(HttpLogEntry::class, 'id'))->setValue($entry, $id);

        return $entry;
    }

    /**
     * Whitespace-insensitive HTML: collapse runs of whitespace and drop whitespace between tags.
     */
    public static function normalize(string $html): string
    {
        $html = (string) preg_replace('/\s+/', ' ', $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);
        $html = (string) preg_replace('/\s+>/', '>', $html);

        return trim($html);
    }

    private function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };
    }
}
