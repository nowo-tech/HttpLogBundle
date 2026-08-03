<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Form;

use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryBuilder;

final class HttpLogFilterTypeTest extends TestCase
{
    #[Test]
    public function formBuildsAllExpectedFieldsAndDefaults(): void
    {
        $form = (new FormFactoryBuilder())
            ->getFormFactory()
            ->create(HttpLogFilterType::class);

        self::assertTrue($form->has('method'));
        self::assertTrue($form->has('routeName'));
        self::assertTrue($form->has('statusCode'));
        self::assertTrue($form->has('clientIp'));
        self::assertTrue($form->has('path'));
        self::assertTrue($form->has('bodyContentType'));
        self::assertTrue($form->has('createdFrom'));
        self::assertTrue($form->has('createdTo'));
        self::assertTrue($form->has('q'));

        self::assertSame(ChoiceType::class, $form->get('method')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(TextType::class, $form->get('routeName')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(IntegerType::class, $form->get('statusCode')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(TextType::class, $form->get('clientIp')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(TextType::class, $form->get('path')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(ChoiceType::class, $form->get('bodyContentType')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(DateTimeType::class, $form->get('createdFrom')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(DateTimeType::class, $form->get('createdTo')->getConfig()->getType()->getInnerType()::class);
        self::assertSame(TextType::class, $form->get('q')->getConfig()->getType()->getInnerType()::class);

        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        self::assertSame('NowoHttpLogBundle', $form->getConfig()->getOption('translation_domain'));
        self::assertSame('GET', $form->getConfig()->getOption('method'));

        $form->submit([
            'method'          => 'POST',
            'routeName'       => '',
            'statusCode'      => '',
            'clientIp'        => '',
            'path'            => '',
            'bodyContentType' => 'json',
            'createdFrom'     => '',
            'createdTo'       => '',
            'q'               => '',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('POST', $form->get('method')->getData());
        self::assertSame('json', $form->get('bodyContentType')->getData());
    }
}
