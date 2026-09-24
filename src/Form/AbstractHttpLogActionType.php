<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
abstract class AbstractHttpLogActionType extends AbstractType
{
    final public function getBlockPrefix(): string
    {
        return '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'allow_extra_fields' => true,
            'csrf_field_name'    => '_token',
            'csrf_protection'    => true,
            'csrf_token_id'      => $this->csrfTokenId(),
            'method'             => 'POST',
        ]);
    }

    abstract protected function csrfTokenId(): string;
}
