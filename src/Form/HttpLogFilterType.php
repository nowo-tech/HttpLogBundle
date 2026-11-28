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
use Symfony\Component\Translation\TranslatableMessage;

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
                'placeholder' => new TranslatableMessage('filter.any_method', [], 'NowoHttpLogBundle'),
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
            $this->addTextField('clientIp', ['required' => false, 'label' => 'filter.client_ip', 'attr' => ['placeholder' => 'filter.client_ip_placeholder']]);
            $this->addTextField('path', ['required' => false, 'label' => 'filter.path', 'attr' => ['placeholder' => 'filter.path_placeholder']]);
            $this->addTextField('routeName', ['required' => false, 'label' => 'filter.route_name', 'attr' => ['placeholder' => 'filter.route_name_placeholder']]);
            $this->addChoiceField('bodyContentType', [
                'required'    => false,
                'label'       => 'filter.body_content_type',
                'placeholder' => new TranslatableMessage('filter.any_body_type', [], 'NowoHttpLogBundle'),
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

        if ($options['correlation'] === true) {
            // 1.27: correlation.columns maps correlation_id / upstream_id (exact match)
            $this->withBuilder($builder, function (): void {
                $this->addTextField('correlationId', ['required' => false, 'label' => 'filter.correlation_id']);
                $this->addTextField('upstreamId', ['required' => false, 'label' => 'filter.upstream_id']);
            });
        }

        /** @var list<string> $channels */
        $channels = $options['usage_channels'];
        if ($channels === []) {
            return;
        }

        // HL-1 usage filters: only while outbound or mailer capture maps the usage columns.
        $this->withBuilder($builder, function () use ($channels): void {
            $this->addChoiceField('channel', [
                'required'    => false,
                'label'       => 'filter.channel',
                'placeholder' => new TranslatableMessage('filter.any_channel', [], 'NowoHttpLogBundle'),
                // Channel names are literals (web, outbound, mailer), not translation keys.
                'choice_translation_domain' => false,
                'choices'                   => array_combine($channels, $channels),
            ]);
            $this->addTextField('provider', ['required' => false, 'label' => 'filter.provider']);
            $this->addTextField('tenantKey', ['required' => false, 'label' => 'filter.tenant_key']);
            $this->addTextField('projectKey', ['required' => false, 'label' => 'filter.project_key']);
            $this->addTextField('credentialKind', ['required' => false, 'label' => 'filter.credential_kind']);
        });

        if ($options['error_code'] === true) {
            // a provider configures outbound error_code (the column is mapped)
            $this->withBuilder($builder, function (): void {
                $this->addTextField('errorCode', ['required' => false, 'label' => 'filter.error_code']);
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'NowoHttpLogBundle',
            // HL-1: channels offered by the channel filter; empty = no usage filters (usage columns not mapped).
            'usage_channels' => [],
        ]);
        $resolver->setAllowedTypes('usage_channels', 'string[]');
        // error_code is mapped (needs usage_channels)
        $resolver->setDefault('error_code', false);
        $resolver->setAllowedTypes('error_code', 'bool');
        // 1.27: correlation_id / upstream_id are mapped
        $resolver->setDefault('correlation', false);
        $resolver->setAllowedTypes('correlation', 'bool');
    }
}
