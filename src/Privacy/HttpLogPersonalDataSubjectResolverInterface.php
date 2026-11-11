<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

/**
 * SPI of the UserKit bridge: which HTTP log subjects belong to a user being erased or exported. Point
 * `nowo_http_log.personal_data.subject_resolver` at your service when the identifier logged for a user differs from
 * `UserInterface::getUserIdentifier()` (e.g. several historical identifiers).
 */
interface HttpLogPersonalDataSubjectResolverInterface
{
    /**
     * @param object $user The user being erased or exported
     * @param string|null $originalIdentifier The identifier the user had when the erasure started (null on export)
     *
     * @return list<HttpLogPersonalDataSubject>
     */
    public function subjectsFor(object $user, ?string $originalIdentifier): array;
}
