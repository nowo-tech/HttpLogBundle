<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy\UserKit;

use InvalidArgumentException;
use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasureInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubjectResolverInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `http_log` (registered with UserKit installed and `personal_data.enabled`): deletes
 * the HTTP log entries of the user's original identifier (and of `context['previous_email']` when the host passes
 * one), through {@see HttpLogPersonalDataErasureInterface}. Priority 100: before the host's identity erasers, after
 * CookieConsent's (110), which reads the client IP windows of these entries first. Joins UserKit's transaction when it
 * runs on the same connection (no event then).
 */
final readonly class HttpLogUserKitPersonalDataEraser implements PersonalDataEraserInterface
{
    public const KEY = 'http_log';

    public const PRIORITY = 100;

    public function __construct(
        private HttpLogPersonalDataErasureInterface $erasure,
        private HttpLogPersonalDataSubjectResolverInterface $subjects,
        private ?HttpLogErasedSubjects $erased = null,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return true;
    }

    public function erase(ErasureRequest $request): void
    {
        // Self-service: the current request is the person's own; an anonymising eraser may already have changed the
        // identifier the recorder would log at kernel.terminate, so the request's own capture is dropped (LY-L2).
        if ($request->isSelfService()) {
            $this->erased?->dropCurrentRequest();
        }
        // UserKit >= 1.12 captures the identifier before any eraser ran; older versions have no such property.
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        $subjects = $this->subjects->subjectsFor($request->user, is_string($original) ? $original : null);
        $previous = $request->context['previous_email'] ?? null;
        if (is_string($previous) && $previous !== '') {
            try {
                $subjects[] = HttpLogPersonalDataSubject::fromUserIdentifier($previous);
            } catch (InvalidArgumentException) {
                // A value the column cannot hold was never logged.
            }
        }

        $done = [];
        foreach ($subjects as $subject) {
            $key = $subject->type . "\0" . $subject->value;
            if (!isset($done[$key])) {
                $done[$key] = true;
                $this->erasure->erase($subject);
            }
        }
    }
}
