<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\UiKitBundle\Theme\Theme;
use Nowo\UiKitBundle\Twig\ThemeExtension;
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
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;

/**
 * Builds a real Twig environment (forms, routing, translations, UiKit scaffolds and theme) for admin page render tests.
 */
final class AdminTwigHarness
{
    public const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    /** Host layout bridge of the fixtures (renders every UiKit canonical block). */
    public const HOST_LAYOUT = 'host/layout.html.twig';

    private readonly FormFactoryInterface $formFactory;

    private readonly Translator $translator;

    private readonly UrlGenerator $router;

    public function __construct()
    {
        $this->translator = new Translator('en');
        $this->translator->addLoader('yaml', new YamlFileLoader());
        $this->translator->addResource('yaml', dirname(__DIR__, 2) . '/src/Resources/translations/NowoHttpLogBundle.en.yaml', 'en', 'NowoHttpLogBundle');
        $this->translator->addResource('yaml', dirname(__DIR__, 2) . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/translations/NowoUiKitBundle.en.yaml', 'en', 'NowoUiKitBundle');

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

        $routes = new RouteCollection();
        $routes->add('nowo_http_log_admin_index', new Route('/admin/http-log'));
        $routes->add('nowo_http_log_admin_show', new Route('/admin/http-log/{id}'));
        $routes->add('nowo_http_log_admin_export', new Route('/admin/http-log/export'));
        $routes->add('nowo_http_log_admin_purge', new Route('/admin/http-log/purge'));
        $routes->add('nowo_http_log_admin_delete', new Route('/admin/http-log/{id}/delete'));
        $this->router = new UrlGenerator($routes, new RequestContext());
    }

    /** URL generator with the admin routes (what the controller's router generates). */
    public function router(): UrlGenerator
    {
        return $this->router;
    }

    /** Real form factory (HttpFoundation + CSRF, FormKit-backed filter type) shared with the controller in functional tests. */
    public function formFactory(): FormFactoryInterface
    {
        return $this->formFactory;
    }

    /**
     * Real Twig environment: bundle views, UiKit (scaffolds, partials, ThemeExtension with the given theme), Symfony
     * Form, routing, translations and the fixture host layout as nowo_ui_kit.layouts.admin.
     *
     * @param array<string, mixed> $globals
     * @param array<string, mixed> $theme processed nowo_ui_kit.theme config
     */
    public function createEnvironment(array $globals = [], array $theme = [], string $framework = 'bootstrap5', ?RequestStack $requestStack = null): Environment
    {
        $root   = dirname(__DIR__, 2);
        $views  = $root . '/src/Resources/views';
        $uiKit  = $root . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views';
        $loader = new FilesystemLoader();
        $loader->addPath(self::FIXTURES_DIR . '/templates');
        $loader->addPath($views, 'NowoHttpLogBundle');
        $loader->addPath($views, '!NowoHttpLogBundle');
        $loader->addPath($uiKit, 'NowoUiKitBundle');
        $loader->addPath($uiKit, 'NowoUiKit');
        $formExtensionFile = (new ReflectionClass(FormExtension::class))->getFileName();
        $loader->addPath(dirname((string) $formExtensionFile, 2) . '/Resources/views/Form');

        $twig = new Environment($loader, ['cache' => false, 'strict_variables' => true, 'autoescape' => 'html']);

        $twig->addExtension(new TranslationExtension($this->translator));
        $twig->addExtension(new RoutingExtension($this->router));
        $twig->addExtension(new AssetExtension(new Packages(
            new PathPackage('/', new EmptyVersionStrategy()),
            ['nowo_ui_kit' => new PathPackage('/bundles/nowouikit', new EmptyVersionStrategy())],
        )));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new StringExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine(['form_div_layout.html.twig'], $twig)),
        ]));

        if (!$requestStack instanceof RequestStack) {
            $request = Request::create('/admin/http-log');
            $request->setSession(new Session(new MockArraySessionStorage()));
            $requestStack = new RequestStack();
            $requestStack->push($request);
        }
        $twig->addExtension(new ThemeExtension(
            Theme::fromConfig($framework, $theme),
            ['admin' => self::HOST_LAYOUT, 'guest' => self::HOST_LAYOUT],
            ['breadcrumb_kit' => false, 'collection' => null],
            $this->translator,
            $requestStack,
        ));

        $app = new AppVariable();
        $app->setRequestStack($requestStack);
        $app->setEnvironment('test');
        $app->setDebug(false);
        $twig->addGlobal('app', $app);
        $twig->addGlobal('nowo_ui_kit_css_framework', $framework);
        $twig->addGlobal('nowo_ui_kit_icon_set', 'bootstrap-icons');
        $twig->addGlobal('nowo_ui_kit_row_actions_display', 'icon');
        $twig->addGlobal('nowo_ui_kit_release_check', ['enabled' => false, 'current_version' => '']);

        foreach ($globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }

        return $twig;
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
                ->setRequestHeaders(['accept' => ['application/json', 'text/plain'], 'cookie' => '[REDACTED]'])
                ->setResponseHeaders(['content-type' => ['application/json']])
                ->setRequestBody('{"name":"x"}')
                ->setRequestBodyTruncated(true)
                ->setResponseBody('{"id":1}');
        }

        (new ReflectionProperty(HttpLogEntry::class, 'id'))->setValue($entry, $id);

        return $entry;
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
