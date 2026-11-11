<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasure;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasureInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporter;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporterInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubjectResolverInterface;
use Nowo\HttpLogBundle\Privacy\UserIdentifierSubjectResolver;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitExportProvider;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitPersonalDataEraser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * GDPR ports (1.18): the ports are always public; the UserKit eraser and export section are registered only with
 * UserKit installed and `personal_data.enabled`.
 */
final class PersonalDataExtensionTest extends TestCase
{
    #[Test]
    public function withUserKitAndOptInTheBridgeIsRegisteredWithItsPriorities(): void
    {
        $container = $this->load(['personal_data' => ['enabled' => true]]);

        self::assertTrue($container->getAlias(HttpLogPersonalDataErasureInterface::class)->isPublic());
        self::assertSame(HttpLogPersonalDataErasure::class, (string) $container->getAlias(HttpLogPersonalDataErasureInterface::class));
        self::assertTrue($container->getAlias(HttpLogPersonalDataExporterInterface::class)->isPublic());
        self::assertSame(HttpLogPersonalDataExporter::class, (string) $container->getAlias(HttpLogPersonalDataExporterInterface::class));
        self::assertSame(UserIdentifierSubjectResolver::class, (string) $container->getAlias(HttpLogPersonalDataSubjectResolverInterface::class));
        self::assertSame(1000, $container->getParameter('nowo_http_log.personal_data.export_limit'));
        self::assertSame([['priority' => 100]], $container->getDefinition(HttpLogUserKitPersonalDataEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertSame([['priority' => -50]], $container->getDefinition(HttpLogUserKitExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
    }

    #[Test]
    public function byDefaultDisabledOrWithoutUserKitNothingIsTagged(): void
    {
        $default = $this->load([]);
        self::assertFalse($default->hasDefinition(HttpLogUserKitPersonalDataEraser::class), 'opt-in (LY-L1): nothing by default');
        self::assertFalse($default->hasDefinition(HttpLogUserKitExportProvider::class));
        self::assertTrue($default->hasAlias(HttpLogPersonalDataErasureInterface::class), 'the ports are always available');

        $disabled = $this->load(['personal_data' => ['enabled' => false]]);
        self::assertFalse($disabled->hasDefinition(HttpLogUserKitPersonalDataEraser::class));
        self::assertFalse($disabled->hasDefinition(HttpLogUserKitExportProvider::class));
        self::assertTrue($disabled->hasAlias(HttpLogPersonalDataErasureInterface::class), 'the ports stay');

        $withoutUserKit = $this->load(['personal_data' => ['enabled' => true]], ['user_kit' => ['Nowo\\Missing\\PersonalDataEraserInterface', 'nowo-tech/user-kit-bundle']] + NowoHttpLogExtension::REQUIRED_SYMBOLS);
        self::assertFalse($withoutUserKit->hasDefinition(HttpLogUserKitPersonalDataEraser::class));
        self::assertFalse($withoutUserKit->hasDefinition(HttpLogUserKitExportProvider::class));
    }

    #[Test]
    public function aCustomResolverAndLimit(): void
    {
        $container = $this->load(['personal_data' => ['subject_resolver' => 'app.http_log_subjects', 'export_limit' => 50]]);

        self::assertSame('app.http_log_subjects', (string) $container->getAlias(HttpLogPersonalDataSubjectResolverInterface::class));
        self::assertSame(50, $container->getParameter('nowo_http_log.personal_data.export_limit'));
    }

    #[Test]
    public function configurationDefaultsAndValidation(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(['enabled' => false, 'export_limit' => 1000, 'subject_resolver' => null], $config['personal_data']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['personal_data' => ['subject_resolver' => '']]]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, array{0: string, 1: string}> $symbols
     */
    private function load(array $config, array $symbols = NowoHttpLogExtension::REQUIRED_SYMBOLS): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension($symbols))->load([['security' => ['allow_unauthenticated' => true]] + $config], $container);

        return $container;
    }
}
