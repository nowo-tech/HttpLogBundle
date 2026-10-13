<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Spec 002 — admin chrome hooks configuration.
 */
final class ConfigurationChromeTest extends TestCase
{
    #[Test]
    public function defaultsMatchTheShippedMarkup(): void
    {
        $webUi = $this->process([])['web_ui'];

        self::assertSame(AdminChrome::DEFAULT_TEMPLATES, $webUi['templates']);
        self::assertSame(AdminChrome::DEFAULT_CSS_CLASSES, $webUi['css_classes']);
        self::assertSame([], $webUi['attributes']);
        self::assertTrue($webUi['ui_kit_stylesheet']);
        self::assertFalse($webUi['header_actions_in_layout']);
        self::assertSame([], $webUi['confirm_attributes']);
        self::assertFalse($webUi['ui_kit_row_actions']);
        self::assertSame(AdminChrome::DEFAULTS, AdminChrome::fromWebUi($webUi));
    }

    #[Test]
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
        self::assertSame(AdminChrome::DEFAULT_TEMPLATES['filter'], $webUi['templates']['filter']);
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
    public function rejectsUnknownCssClassKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['css_classes' => ['tabel' => 'typo']]);
    }

    #[Test]
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
