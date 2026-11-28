<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Form\HttpLogDeleteType;
use Nowo\HttpLogBundle\Form\HttpLogFilterType;
use Nowo\HttpLogBundle\Form\HttpLogPurgeType;
use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\Tests\Support\AdminTwigHarness;
use Nowo\HttpLogBundle\View\AdminPages;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_keys;
use function array_map;
use function count;

/**
 * HL-1 §11.2: with the usage columns mapped the list renders the channel / provider cells and the entry page the
 * allowlisted usage fields; without them the pages are those of 1.14.
 */
final class UsageAdminPagesRenderTest extends TestCase
{
    #[Test]
    public function listRendersChannelAndProviderCellsOnlyWithUsageColumns(): void
    {
        $outbound = $this->usage(AdminTwigHarness::entry(51), ['channel' => 'outbound', 'provider' => 'clickup']);
        $web      = AdminTwigHarness::entry(52);
        $harness  = new AdminTwigHarness();
        $forms    = $harness->formFactory();
        $pages    = new AdminPages($harness->router(), [], true);
        $page     = $pages->listPage(
            [$outbound, $web],
            2,
            1,
            10,
            $forms->create(HttpLogFilterType::class)->createView(),
            [],
            $forms->create(HttpLogPurgeType::class, null, ['action' => '/admin/http-log/purge'])->createView(),
        );

        $html = $harness->createEnvironment()->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => $page, 'criteria' => []]);
        self::assertStringContainsString('nowo-http-log-channel">outbound<', $html);
        self::assertStringContainsString('nowo-http-log-channel">web<', $html);
        self::assertStringContainsString('clickup', $html);
        self::assertSame(9, count($page->columns));
        self::assertStringNotContainsString('admin.column.error_code', $html);

        $plain = $harness->createEnvironment()->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => AdminPageFixtures::listPage(AdminPageFixtures::entries(), 3), 'criteria' => []]);
        self::assertStringNotContainsString('nowo-http-log-channel', $plain);
    }

    #[Test]
    public function listAndEntryShowTheErrorCodeOnlyWhenItsColumnIsMapped(): void
    {
        $failed  = $this->usage(AdminTwigHarness::entry(61), ['channel' => 'outbound', 'provider' => 'login', 'errorCode' => 'invalid_client']);
        $harness = new AdminTwigHarness();
        $forms   = $harness->formFactory();
        $render  = static function (bool $errorCode) use ($harness, $forms, $failed): array {
            $pages = new AdminPages($harness->router(), [], true, $errorCode);
            $page  = $pages->listPage([$failed], 1, 1, 10, $forms->create(HttpLogFilterType::class)->createView(), [], $forms->create(HttpLogPurgeType::class, null, ['action' => '/x'])->createView());
            $show  = $pages->showPage($failed, $forms->create(HttpLogDeleteType::class, null, ['action' => '/x'])->createView());

            return [
                $harness->createEnvironment()->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => $page, 'criteria' => []]),
                count($page->columns),
                array_map(static fn (DetailItem $i): mixed => $i->value, $show->sections[0]->items),
            ];
        };

        [$html, $columns, $values] = $render(true);
        self::assertStringContainsString('invalid_client', $html);
        self::assertSame(10, $columns);
        self::assertContains('invalid_client', $values);

        [$html, $columns, $values] = $render(false);
        self::assertStringNotContainsString('invalid_client', $html);
        self::assertSame(9, $columns);
        self::assertNotContains('invalid_client', $values);
    }

    #[Test]
    public function correlationFiltersAndFieldsExistOnlyWhileTheColumnsAreMapped(): void
    {
        $entry   = AdminTwigHarness::entry(71)->setCorrelationId('req-71')->setUpstreamId('up-71');
        $harness = new AdminTwigHarness();
        $forms   = $harness->formFactory();
        $render  = static function (bool $correlation) use ($harness, $forms, $entry): array {
            $pages = new AdminPages($harness->router(), [], false, false, $correlation);
            $list  = $pages->listPage([$entry], 1, 1, 10, $forms->create(HttpLogFilterType::class, null, ['correlation' => $correlation])->createView(), [], $forms->create(HttpLogPurgeType::class, null, ['action' => '/x'])->createView());
            $show  = $pages->showPage($entry, $forms->create(HttpLogDeleteType::class, null, ['action' => '/x'])->createView());
            $items = [];
            foreach ($show->sections[0]->items as $item) {
                self::assertInstanceOf(Label::class, $item->label);
                $items[$item->label->key] = $item->value;
            }

            return [array_keys($list->filterFields), $items, $harness->createEnvironment()->render('@NowoHttpLogBundle/admin/index.html.twig', ['page' => $list, 'criteria' => []])];
        };

        [$filters, $items, $html] = $render(true);
        self::assertContains('correlationId', $filters);
        self::assertContains('upstreamId', $filters);
        self::assertSame('req-71', $items['admin.field.correlation_id']);
        self::assertSame('up-71', $items['admin.field.upstream_id']);
        self::assertStringContainsString('correlationId', $html);

        [$filters, $items, $html] = $render(false);
        self::assertNotContains('correlationId', $filters);
        self::assertArrayNotHasKey('admin.field.correlation_id', $items);
        self::assertStringNotContainsString('correlationId', $html);
    }

    #[Test]
    public function entryPageShowsTheAllowlistedUsageFields(): void
    {
        $entry = $this->usage(AdminTwigHarness::entry(53), [
            'channel'            => 'mailer',
            'provider'           => 'resend',
            'credentialKind'     => 'personal_token',
            'credentialId'       => '42',
            'tenantKey'          => 'org-1',
            'projectKey'         => 'project-1',
            'errorKind'          => 'http_5xx',
            'errorCode'          => 'invalid_client,ERR7000215',
            'retryCount'         => 2,
            'responseBytes'      => '2048',
            'messengerMessageId' => 'msg-1',
            'externalMessageId'  => 'id-1@mail.example',
        ]);
        $harness = new AdminTwigHarness();
        $pages   = new AdminPages($harness->router(), [], true, true);
        $show    = $pages->showPage($entry, $harness->formFactory()->create(HttpLogDeleteType::class, null, ['action' => '/x'])->createView());

        $items = [];
        foreach ($show->sections[0]->items as $item) {
            self::assertInstanceOf(DetailItem::class, $item);
            self::assertInstanceOf(Label::class, $item->label);
            $items[$item->label->key] = $item->value;
        }
        self::assertSame('mailer', $items['admin.column.channel']);
        self::assertSame('personal_token:42', $items['admin.field.credential']);
        self::assertSame(2048, $items['admin.field.response_bytes']);
        self::assertSame('invalid_client,ERR7000215', $items['admin.column.error_code']);

        $html = $harness->createEnvironment()->render('@NowoHttpLogBundle/admin/show.html.twig', ['page' => $show, 'entry' => $entry, 'payload' => $pages->payload($entry)]);
        self::assertStringContainsString('id-1@mail.example', $html);

        $kindOnly = $this->usage(AdminTwigHarness::entry(54), ['credentialKind' => 'dsn_key']);
        $labels   = array_map(static fn (DetailItem $i): mixed => $i->value, $pages->showPage($kindOnly, $harness->formFactory()->create(HttpLogDeleteType::class, null, ['action' => '/x'])->createView())->sections[0]->items);
        self::assertContains('dsn_key', $labels);
        self::assertContains('web', $labels);
    }

    /** @param array<string, mixed> $values */
    private function usage(HttpLogEntry $entry, array $values): HttpLogEntry
    {
        foreach ($values as $property => $value) {
            (new ReflectionProperty(HttpLogEntry::class, $property))->setValue($entry, $value);
        }

        return $entry;
    }
}
