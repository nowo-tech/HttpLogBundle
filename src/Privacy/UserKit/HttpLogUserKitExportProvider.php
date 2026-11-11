<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy\UserKit;

use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporterInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubjectResolverInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * UserKit export section `http_requests` (registered with UserKit installed and `personal_data.enabled`): one
 * {@see HttpLogPersonalDataExporterInterface::export()} document per subject of the user (one with the default
 * resolver), request metadata only.
 */
final readonly class HttpLogUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'http_requests';

    /** UserKit orders sections by priority (higher first); built-in sections use negative priorities. */
    public const PRIORITY = -50;

    public function __construct(
        private HttpLogPersonalDataExporterInterface $exporter,
        private HttpLogPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return $this->subjects->subjectsFor($user, null) !== [];
    }

    /** @return list<array<string, mixed>> */
    public function export(object $user): array
    {
        $out = [];
        foreach ($this->subjects->subjectsFor($user, null) as $subject) {
            $out[] = $this->exporter->export($subject);
        }

        return $out;
    }
}
