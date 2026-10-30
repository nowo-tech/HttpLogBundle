<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\HttpLogBundle\Form\HttpLogDeleteType;
use Nowo\HttpLogBundle\Form\HttpLogExportType;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function class_uses;
use function glob;
use function in_array;

/**
 * REQ-TWIG-005 rule 11: every page form type of the bundle is a FormKit Type bound to the {@code http_log} profile.
 */
#[CoversNothing]
final class FormKitTypesContractTest extends TestCase
{
    #[Test]
    public function everyFormTypeCarriesTheHttpLogProfile(): void
    {
        $files = glob(__DIR__ . '/../../../src/Form/*Type.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            /** @var class-string $class */
            $class      = 'Nowo\\HttpLogBundle\\Form\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            $profile    = null;
            $uses       = false;
            for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
                $attributes = $current->getAttributes(FormKitConfig::class);
                if ($profile === null && $attributes !== []) {
                    $profile = $attributes[0]->newInstance()->name;
                }
                $uses = $uses || in_array(FormOptionsTrait::class, class_uses($current->getName()), true);
            }

            self::assertSame('http_log', $profile, $class . ' resolves #[FormKitConfig(\'http_log\')].');
            self::assertTrue($uses, $class . ' uses FormOptionsTrait.');
        }
    }

    #[Test]
    public function concreteActionTypesResolveTheProfileAtRuntime(): void
    {
        foreach ([new HttpLogDeleteType(), new HttpLogPurgeType(), new HttpLogExportType(), new HttpLogFilterType()] as $type) {
            self::assertSame('http_log', FormKitConfig::nameFrom($type));
        }
    }
}
