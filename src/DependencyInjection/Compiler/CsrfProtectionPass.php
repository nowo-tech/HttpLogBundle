<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the admin UI is enabled without form CSRF protection (REQ-SEC-005).
 *
 * The mutations of the admin UI rely on Symfony's form CSRF extension: forms that declare their own `csrf_protection` /
 * `csrf_token_id` options would otherwise validate without a token when `form.type_extension.csrf` is missing or
 * `framework.form.csrf_protection` is false. The container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /** Parameter whose value is true when the admin UI is registered. */
    public const ENABLED_PARAMETER = 'nowo_http_log.web_ui.enabled';

    /**
     * Throws when the admin UI is enabled and form CSRF protection or the CSRF token manager is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ENABLED_PARAMETER) || $container->getParameter(self::ENABLED_PARAMETER) !== true) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoHttpLogBundle: the admin UI (web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf), or set nowo_http_log.web_ui.enabled: false.');
        }
    }
}
