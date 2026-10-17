<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Form\HttpLogDeleteType;
use Nowo\HttpLogBundle\Form\HttpLogExportType;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use Nowo\HttpLogBundle\View\AdminPages;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\ShowPage;
use ReflectionProperty;

/**
 * Realistic admin page view models (built by {@see AdminPages}, as the controller does) for the page contract test
 * and the render tests.
 */
final class AdminPageFixtures
{
    /**
     * @param array<string, mixed> $webUi
     */
    public static function pages(array $webUi = []): AdminPages
    {
        return new AdminPages((new AdminTwigHarness())->router(), $webUi);
    }

    /**
     * @param list<HttpLogEntry> $entries
     * @param array<string, mixed> $webUi
     */
    public static function listPage(array $entries, int $total, bool $filtered = false, array $webUi = [], int $page = 1, int $pageSize = 2): ListPage
    {
        $forms = (new AdminTwigHarness())->formFactory();
        $data  = $filtered ? ['method' => 'POST', 'statusCode' => 201] : null;

        return self::pages($webUi)->listPage(
            $entries,
            $total,
            $page,
            $pageSize,
            $forms->create(HttpLogFilterType::class, $data)->createView(),
            [
                'csv'  => $forms->create(HttpLogExportType::class, null, ['action' => '/admin/http-log/export', 'format' => 'csv', 'criteria' => $data ?? []])->createView(),
                'json' => $forms->create(HttpLogExportType::class, null, ['action' => '/admin/http-log/export', 'format' => 'json', 'criteria' => $data ?? []])->createView(),
            ],
            $forms->create(HttpLogPurgeType::class, null, ['action' => '/admin/http-log/purge'])->createView(),
            $filtered,
        );
    }

    /**
     * @param array<string, mixed> $webUi
     */
    public static function showPage(HttpLogEntry $entry, array $webUi = []): ShowPage
    {
        $forms = (new AdminTwigHarness())->formFactory();

        return self::pages($webUi)->showPage(
            $entry,
            $forms->create(HttpLogDeleteType::class, null, ['action' => '/admin/http-log/' . $entry->getId() . '/delete'])->createView(),
        );
    }

    /**
     * Three entries with different methods / statuses (2xx, 4xx, 5xx) and an entry without client IP.
     *
     * @return list<HttpLogEntry>
     */
    public static function entries(): array
    {
        $ok = AdminTwigHarness::entry(41);

        $notFound = AdminTwigHarness::entry(42)
            ->setMethod('GET')
            ->setPath('/products/unknown-sku')
            ->setRouteName('app_product_show')
            ->setStatusCode(404)
            ->setDurationMs(4.2)
            ->setClientIp(null)
            ->setCreatedAt(new DateTimeImmutable('2026-08-03T10:05:00+00:00'));

        $error = AdminTwigHarness::entry(43)
            ->setMethod('DELETE')
            ->setPath('/api/orders/981')
            ->setRouteName('api_order_delete')
            ->setStatusCode(500)
            ->setDurationMs(812.75)
            ->setCreatedAt(new DateTimeImmutable('2026-08-03T10:07:30+00:00'));

        return [$ok, $notFound, $error];
    }

    public static function withId(HttpLogEntry $entry, int $id): HttpLogEntry
    {
        (new ReflectionProperty(HttpLogEntry::class, 'id'))->setValue($entry, $id);

        return $entry;
    }
}
