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

    /** No-JS: the FormView-backed export and delete forms post their CSRF token (and export format + criteria). */
    public function testAdminExportAndDeleteFormsSubmitWithTheirCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        $auth    = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'admin'];
        $crawler = $client->request('GET', '/admin/http-log?http_log_filter%5Bmethod%5D=GET', [], [], $auth);
        self::assertResponseIsSuccessful();

        $export = $crawler->filter('form[action="/admin/http-log/export"]')->reduce(
            static fn ($form): bool => $form->filter('input[name="format"][value="json"]')->count() === 1,
        );
        self::assertCount(1, $export);
        self::assertCount(1, $export->filter('input[name="method"][value="GET"]'), 'criteria travel as hidden fields');
        self::assertCount(1, $export->filter('input[name="_token"]'));

        $client->submit($export->form(), [], $auth);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="http-log-export.json"');
        $rows = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($rows);
        self::assertNotEmpty($rows);
        self::assertSame(['GET'], array_values(array_unique(array_column($rows, 'method'))));

        $client->request('POST', '/admin/http-log/export', ['_token' => 'forged', 'format' => 'json'], [], $auth);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/http-log', [], [], $auth);
        $show    = (string) $crawler->filter('[data-nowo-ui-region="table"] tbody a')->first()->attr('href');
        $crawler = $client->request('GET', $show, [], [], $auth);
        self::assertSelectorExists('pre.nowo-ui-code-block.nowo-http-log-code');

        $delete = $crawler->filter('form[action="' . $show . '/delete"]');
        self::assertCount(1, $delete);
        $client->submit($delete->form(), [], $auth);
        self::assertResponseRedirects('/admin/http-log');

        $client->request('GET', $show, [], [], $auth);
        self::assertResponseStatusCodeSame(404);
    }
}
