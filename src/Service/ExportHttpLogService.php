<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use RuntimeException;

use function fclose;
use function fputcsv;
use function fwrite;
use function is_resource;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Exports HTTP log entries to CSV or JSON.
 */
final class ExportHttpLogService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly HttpLogEntryRepository $repository,
        private readonly ?Closure $tempStreamOpener = null,
        private readonly ?Closure $streamReader = null,
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function countForCriteria(array $criteria): int
    {
        return $this->repository->countFiltered($criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exportToString(array $criteria, ExportFormat $format): string
    {
        $handle = ($this->tempStreamOpener ?? static fn () => fopen('php://temp', 'r+'))();
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary stream for export.');
        }

        try {
            $this->exportToResource($criteria, $format, $handle);
            rewind($handle);
            $content = ($this->streamReader ?? stream_get_contents(...))($handle);
            if ($content === false) {
                throw new RuntimeException('Unable to read export stream.');
            }

            return $content;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return int Number of exported rows
     */
    public function exportToResource(array $criteria, ExportFormat $format, mixed $resource): int
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Export resource must be a valid stream.');
        }

        return match ($format) {
            ExportFormat::Csv  => $this->exportCsv($criteria, $resource),
            ExportFormat::Json => $this->exportJson($criteria, $resource),
        };
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exportToFile(array $criteria, ExportFormat $format, string $path): int
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open export file: %s', $path));
        }

        try {
            return $this->exportToResource($criteria, $format, $handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $criteria */
    private function exportCsv(array $criteria, mixed $resource): int
    {
        $headers = [
            'id', 'requestId', 'method', 'scheme', 'host', 'path', 'routeName',
            'statusCode', 'clientIp', 'contentType', 'bodyContentType', 'durationMs',
            'userIdentifier', 'createdAt',
        ];
        fputcsv($resource, $headers);

        $page  = 1;
        $total = 0;

        do {
            $result = $this->repository->findFiltered($criteria, $page, self::BATCH_SIZE);
            foreach ($result['items'] as $entry) {
                fputcsv($resource, $this->entryToCsvRow($entry));
                ++$total;
            }
            $this->repository->detachAll($result['items']);
            ++$page;
        } while ($result['items'] !== []);

        return $total;
    }

    /** @param array<string, mixed> $criteria */
    private function exportJson(array $criteria, mixed $resource): int
    {
        fwrite($resource, '[');
        $page  = 1;
        $total = 0;
        $first = true;

        do {
            $result = $this->repository->findFiltered($criteria, $page, self::BATCH_SIZE);
            foreach ($result['items'] as $entry) {
                if (!$first) {
                    fwrite($resource, ',');
                }
                fwrite($resource, json_encode($this->entryToArray($entry), JSON_THROW_ON_ERROR));
                $first = false;
                ++$total;
            }
            $this->repository->detachAll($result['items']);
            ++$page;
        } while ($result['items'] !== []);

        fwrite($resource, ']');

        return $total;
    }

    /** @return list<float|int|string|null> */
    private function entryToCsvRow(HttpLogEntry $entry): array
    {
        return [
            $entry->getId(),
            $entry->getRequestId(),
            $entry->getMethod(),
            $entry->getScheme(),
            $entry->getHost(),
            $entry->getPath(),
            $entry->getRouteName(),
            $entry->getStatusCode(),
            $entry->getClientIp(),
            $entry->getContentType(),
            $entry->getBodyContentType(),
            $entry->getDurationMs(),
            $entry->getUserIdentifier(),
            $entry->getCreatedAt()->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function entryToArray(HttpLogEntry $entry): array
    {
        return [
            'id'                    => $entry->getId(),
            'requestId'             => $entry->getRequestId(),
            'method'                => $entry->getMethod(),
            'scheme'                => $entry->getScheme(),
            'host'                  => $entry->getHost(),
            'path'                  => $entry->getPath(),
            'routeName'             => $entry->getRouteName(),
            'queryParams'           => $entry->getQueryParams(),
            'statusCode'            => $entry->getStatusCode(),
            'clientIp'              => $entry->getClientIp(),
            'contentType'           => $entry->getContentType(),
            'bodyContentType'       => $entry->getBodyContentType(),
            'durationMs'            => $entry->getDurationMs(),
            'userIdentifier'        => $entry->getUserIdentifier(),
            'requestHeaders'        => $entry->getRequestHeaders(),
            'responseHeaders'       => $entry->getResponseHeaders(),
            'requestBody'           => $entry->getRequestBody(),
            'responseBody'          => $entry->getResponseBody(),
            'requestBodyTruncated'  => $entry->isRequestBodyTruncated(),
            'responseBodyTruncated' => $entry->isResponseBodyTruncated(),
            'responseBodyStored'    => $entry->isResponseBodyStored(),
            'createdAt'             => $entry->getCreatedAt()->format(DateTimeImmutable::ATOM),
        ];
    }
}
