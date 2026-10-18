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
        // Field order = order in the filter row (REQ-UI-003 list filters): what (search, method, status, client IP),
        // where (path, route, body type), when (created from / to).
        $this->withBuilder($builder, function (): void {
            $this->addTextField('q', ['required' => false, 'label' => 'filter.q', 'attr' => ['placeholder' => 'filter.q_placeholder']]);
            $this->addChoiceField('method', [
                'required'    => false,
                'label'       => 'filter.method',
                'placeholder' => 'filter.any',
                // HTTP methods are literals: not looked up in the bundle domain (REQ: no profiler missing messages).
                'choice_translation_domain' => false,
                'choices'                   => [
                    'GET'     => 'GET',
                    'POST'    => 'POST',
                    'PUT'     => 'PUT',
                    'PATCH'   => 'PATCH',
                    'DELETE'  => 'DELETE',
                    'HEAD'    => 'HEAD',
                    'OPTIONS' => 'OPTIONS',
                ],
            ]);
            $this->addIntegerField('statusCode', ['required' => false, 'label' => 'filter.status_code', 'attr' => ['min' => 100, 'max' => 599, 'placeholder' => 'filter.status_code_placeholder']]);
            $this->addTextField('clientIp', ['required' => false, 'label' => 'filter.client_ip']);
            $this->addTextField('path', ['required' => false, 'label' => 'filter.path', 'attr' => ['placeholder' => 'filter.path_placeholder']]);
            $this->addTextField('routeName', ['required' => false, 'label' => 'filter.route_name', 'attr' => ['placeholder' => 'filter.route_name_placeholder']]);
            $this->addChoiceField('bodyContentType', [
                'required'    => false,
                'label'       => 'filter.body_content_type',
                'placeholder' => 'filter.any',
                // Body types are enum values (json, xml…), not translation keys.
                'choice_translation_domain' => false,
                'choices'                   => array_combine(
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                    array_map(static fn (BodyContentType $t): string => $t->value, BodyContentType::cases()),
                ),
            ]);
            // Native date-and-time picker (datetime-local), minute precision.
            $this->addTypedField('createdFrom', DateTimeType::class, [
                'required'     => false,
                'label'        => 'filter.created_from',
                'widget'       => 'single_text',
                'html5'        => true,
                'with_seconds' => false,
                'input'        => 'datetime_immutable',
            ]);
            $this->addTypedField('createdTo', DateTimeType::class, [
                'required'     => false,
                'label'        => 'filter.created_to',
                'widget'       => 'single_text',
                'html5'        => true,
                'with_seconds' => false,
                'input'        => 'datetime_immutable',
            ]);
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
