<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;
use function is_scalar;

final class HttpLogExportType extends AbstractHttpLogActionType
{
    /** @var list<string> */
    private const CRITERIA_FIELDS = [
        'method',
        'routeName',
        'statusCode',
        'clientIp',
        'path',
        'bodyContentType',
        'q',
        'createdFrom',
        'createdTo',
    ];

    /**
     * @param array{criteria: array<string, mixed>, format: string} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('format', HiddenType::class, [
            'data' => $options['format'],
        ]);

        foreach ($options['criteria'] as $name => $value) {
            if (!in_array($name, self::CRITERIA_FIELDS, true) || !is_scalar($value)) {
                continue;
            }

            $builder->add($name, HiddenType::class, [
                'data' => (string) $value,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'criteria' => [],
            'format'   => 'csv',
        ]);
        $resolver->setAllowedTypes('criteria', 'array');
        $resolver->setAllowedTypes('format', 'string');
    }

    protected function csrfTokenId(): string
    {
        return 'http_log_export';
    }
}
