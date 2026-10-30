<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only action form of the admin pages (delete, purge, export). A FormKit Type bound to the {@code http_log}
 * profile (REQ-TWIG-005 rule 11); the attribute is inherited by every concrete action type. The children are hidden
 * markers only, so they are added without profile defaults (no field classes on hidden inputs).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('http_log')]
abstract class AbstractHttpLogActionType extends AbstractType
{
    use FormOptionsTrait;

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
