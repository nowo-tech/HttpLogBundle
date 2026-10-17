<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\LegacyThemeBridge;
use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Spec 002 — admin chrome hooks configuration; deprecated since 1.8 (REQ-UI-003), bridged into the UiKit theme.
 */
final class ConfigurationChromeTest extends TestCase
{
    #[Test]
    public function defaultsMatchTheShippedMarkup(): void
    {
        $webUi = $this->process([])['web_ui'];

        self::assertSame(Configuration::PAGE_TEMPLATES + ['filter' => null, 'pagination' => null], $webUi['templates']);
        self::assertNull($webUi['layout_template'], 'null = UiKit admin layout');
        self::assertSame(AdminChrome::DEFAULT_CSS_CLASSES, $webUi['css_classes']);
        self::assertSame([], $webUi['attributes']);
        self::assertTrue($webUi['ui_kit_stylesheet']);
        self::assertFalse($webUi['header_actions_in_layout']);
        self::assertSame([], $webUi['confirm_attributes']);
        self::assertFalse($webUi['ui_kit_row_actions']);
        self::assertSame(AdminChrome::DEFAULTS, AdminChrome::fromWebUi($webUi));
        self::assertSame([], LegacyThemeBridge::themeException($webUi), 'defaults bridge nothing');
    }

    #[Test]
    public function legacyBundleLayoutMeansTheUiKitLayout(): void
    {
        self::assertNull($this->process(['layout_template' => Configuration::LEGACY_LAYOUT_TEMPLATE])['web_ui']['layout_template']);
        self::assertSame('layouts/admin.html.twig', $this->process(['layout_template' => 'layouts/admin.html.twig'])['web_ui']['layout_template']);
    }

    /**
     * @param array<string, mixed> $webUi
     */
    #[Test]
    #[IgnoreDeprecations]
    #[DataProvider('deprecatedKeys')]
    public function deprecatedKeysTriggerADeprecation(array $webUi, string $message): void
    {
        $this->expectUserDeprecationMessageMatches($message);
        $this->process($webUi);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function deprecatedKeys(): iterable
    {
        yield 'css_framework' => [['css_framework' => 'tailwind'], '/"nowo_http_log\\.web_ui\\.css_framework" option is deprecated, set "nowo_ui_kit\\.css_framework"/'];
        yield 'icon_set' => [['icon_set' => 'tabler-icons'], '/web_ui\\.icon_set" option is deprecated, set "nowo_ui_kit\\.icon_set"/'];
        yield 'css_classes' => [['css_classes' => ['table' => 'x']], '/web_ui\\.css_classes" option is deprecated, use "nowo_ui_kit\\.theme\\.bundles\\.NowoHttpLogBundle\\.classes"/'];
        yield 'attributes' => [['attributes' => ['table' => ['data-x' => '1']]], '/web_ui\\.attributes" option is deprecated/'];
        yield 'templates.filter' => [['templates' => ['filter' => 'x.html.twig']], '/templates\\.filter" option is deprecated, use "nowo_ui_kit\\.theme\\.bundles\\.NowoHttpLogBundle\\.partials\\.list_filters"/'];
        yield 'templates.pagination' => [['templates' => ['pagination' => 'x.html.twig']], '/templates\\.pagination" option is deprecated/'];
        yield 'ui_kit_stylesheet' => [['ui_kit_stylesheet' => false], '/web_ui\\.ui_kit_stylesheet" option is deprecated and has no effect/'];
        yield 'header_actions_in_layout' => [['header_actions_in_layout' => true], '/web_ui\\.header_actions_in_layout" option is deprecated and has no effect/'];
        yield 'confirm_attributes' => [['confirm_attributes' => ['data-x' => '1']], '/web_ui\\.confirm_attributes" option is deprecated and has no effect/'];
        yield 'ui_kit_row_actions' => [['ui_kit_row_actions' => true], '/web_ui\\.ui_kit_row_actions" option is deprecated and has no effect/'];
    }

    #[Test]
    #[IgnoreDeprecations]
    public function acceptsOverridesAndKeepsDashedAttributeNames(): void
    {
        $webUi = $this->process([
            'templates'   => ['pagination' => 'kit/_pagination.html.twig'],
            'css_classes' => ['table' => 'data-table w-full', 'btn_purge' => 'btn-danger'],
            'attributes'  => [
                'results'     => ['data-testid' => 'http-log-results'],
                'filter_form' => ['data-testid' => 'http-log-filters', 'role' => 'search'],
            ],
            'ui_kit_stylesheet'        => false,
            'header_actions_in_layout' => true,
            'confirm_attributes'       => [
                'data-controller'                   => 'confirm-submit',
                'data-confirm-submit-message-value' => '{message}',
            ],
            'ui_kit_row_actions' => true,
        ])['web_ui'];

        self::assertSame('kit/_pagination.html.twig', $webUi['templates']['pagination']);
        self::assertNull($webUi['templates']['filter']);
        self::assertSame('data-table w-full', $webUi['css_classes']['table']);
        self::assertSame('btn-danger', $webUi['css_classes']['btn_purge']);
        self::assertSame(['data-testid' => 'http-log-results'], $webUi['attributes']['results']);
        self::assertSame('search', $webUi['attributes']['filter_form']['role']);
        self::assertFalse($webUi['ui_kit_stylesheet']);
        self::assertTrue($webUi['header_actions_in_layout']);
        self::assertSame('{message}', $webUi['confirm_attributes']['data-confirm-submit-message-value']);
        self::assertTrue($webUi['ui_kit_row_actions']);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function rejectsUnknownCssClassKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['css_classes' => ['tabel' => 'typo']]);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function rejectsUnknownAttributeTarget(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('web_ui.attributes keys must be one of');
        $this->process(['attributes' => ['body' => ['data-x' => '1']]]);
    }

    #[Test]
    public function rejectsEmptyTemplatePath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['templates' => ['index' => '']]);
    }

    /**
     * @param array<string, mixed> $webUi
     *
     * @return array<string, mixed>
     */
    private function process(array $webUi): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['web_ui' => $webUi]]);
    }
}
