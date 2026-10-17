<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomePageIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'HTTP request / response logging');
        self::assertSelectorTextContains('body', 'HttpLogBundle');
    }

    public function testPingEndpointReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent() ?: '');
    }

    public function testAdminListAndEntryPagesRenderOnTheUiKitScaffolds(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        $auth    = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'admin'];
        $crawler = $client->request('GET', '/admin/http-log', [], [], $auth);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-nowo-ui-page="list"][data-testid="http-log-list"]');
        self::assertSelectorExists('[data-nowo-ui-region="filters"]');
        self::assertSelectorTextContains('h1', 'HTTP Log Entries');
        self::assertSelectorNotExists('[onsubmit]');

        $link = $crawler->filter('[data-nowo-ui-region="table"] tbody a')->first();
        self::assertCount(1, $link, 'the API call above is logged (async: false)');

        $client->request('GET', (string) $link->attr('href'), [], [], $auth);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-nowo-ui-page="show"][data-testid="http-log-show"]');
        self::assertSelectorExists('dialog[data-nowo-ui-confirm]');
    }
}
