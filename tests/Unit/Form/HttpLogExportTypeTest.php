<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Form;

use Nowo\HttpLogBundle\Form\HttpLogExportType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class HttpLogExportTypeTest extends TestCase
{
    #[Test]
    public function formBuildsFormatAndScalarCriteriaFieldsOnly(): void
    {
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);

        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrfTokenManager))
            ->getFormFactory()
            ->create(HttpLogExportType::class, null, [
                'criteria' => [
                    'method'       => 'GET',
                    'statusCode'   => 200,
                    'unknownField' => 'skip-me',
                    'path'         => ['not', 'scalar'],
                    'q'            => 'search',
                ],
                'format' => 'json',
            ]);

        self::assertTrue($form->has('format'));
        self::assertSame(HiddenType::class, $form->get('format')->getConfig()->getType()->getInnerType()::class);
        self::assertSame('json', $form->get('format')->getData());

        self::assertTrue($form->has('method'));
        self::assertSame('GET', $form->get('method')->getData());
        self::assertTrue($form->has('statusCode'));
        self::assertSame('200', $form->get('statusCode')->getData());
        self::assertTrue($form->has('q'));
        self::assertSame('search', $form->get('q')->getData());

        self::assertFalse($form->has('unknownField'));
        self::assertFalse($form->has('path'));

        self::assertSame('http_log_export', $form->getConfig()->getOption('csrf_token_id'));
        self::assertSame('', $form->getName());
        self::assertSame('POST', $form->getConfig()->getOption('method'));
        self::assertTrue($form->getConfig()->getOption('csrf_protection'));
    }

    #[Test]
    public function configureOptionsDefaultsToEmptyCriteriaAndCsvFormat(): void
    {
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);

        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrfTokenManager))
            ->getFormFactory()
            ->create(HttpLogExportType::class);

        self::assertTrue($form->has('format'));
        self::assertSame('csv', $form->get('format')->getData());
        self::assertSame([], $form->getConfig()->getOption('criteria'));
        self::assertSame('csv', $form->getConfig()->getOption('format'));
    }
}
