<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\HttpLogBundle\Enum\BodyContentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('http_log')]
final class HttpLogFilterType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addChoiceField('method', [
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
            ]);
            $this->addTextField('routeName', ['required' => false, 'label' => 'filter.route_name']);
            $this->addIntegerField('statusCode', ['required' => false, 'label' => 'filter.status_code']);
            $this->addTextField('clientIp', ['required' => false, 'label' => 'filter.client_ip']);
            $this->addTextField('path', ['required' => false, 'label' => 'filter.path']);
            $this->addChoiceField('bodyContentType', [
                'required' => false,
                'label'    => 'filter.body_content_type',
                'choices'  => array_combine(
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                ),
            ]);
            $this->addTypedField('createdFrom', DateTimeType::class, [
                'required' => false,
                'label'    => 'filter.created_from',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ]);
            $this->addTypedField('createdTo', DateTimeType::class, [
                'required' => false,
                'label'    => 'filter.created_to',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ]);
            $this->addTextField('q', ['required' => false, 'label' => 'filter.q']);
        });
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
