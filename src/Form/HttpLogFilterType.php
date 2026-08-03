<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

use Nowo\HttpLogBundle\Enum\BodyContentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class HttpLogFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('method', ChoiceType::class, [
                'required' => false,
                'label'    => 'filter.method',
                'choices'  => [
                    'GET'     => 'GET',
                    'POST'    => 'POST',
                    'PUT'     => 'PUT',
                    'PATCH'   => 'PATCH',
                    'DELETE'  => 'DELETE',
                    'HEAD'    => 'HEAD',
                    'OPTIONS' => 'OPTIONS',
                ],
            ])
            ->add('routeName', TextType::class, ['required' => false, 'label' => 'filter.route_name'])
            ->add('statusCode', IntegerType::class, ['required' => false, 'label' => 'filter.status_code'])
            ->add('clientIp', TextType::class, ['required' => false, 'label' => 'filter.client_ip'])
            ->add('path', TextType::class, ['required' => false, 'label' => 'filter.path'])
            ->add('bodyContentType', ChoiceType::class, [
                'required' => false,
                'label'    => 'filter.body_content_type',
                'choices'  => array_combine(
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                ),
            ])
            ->add('createdFrom', DateTimeType::class, [
                'required' => false,
                'label'    => 'filter.created_from',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('createdTo', DateTimeType::class, [
                'required' => false,
                'label'    => 'filter.created_to',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('q', TextType::class, ['required' => false, 'label' => 'filter.q']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'NowoHttpLogBundle',
        ]);
    }
}
