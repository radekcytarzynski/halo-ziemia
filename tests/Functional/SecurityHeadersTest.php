<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testHtmlPagesHaveStrictCspWithNonceMatchingInlineScripts(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/", $csp, $m));

        $inline = $crawler->filter('script:not([src])');
        self::assertGreaterThan(0, $inline->count());
        foreach ($inline as $script) {
            self::assertInstanceOf(\DOMElement::class, $script);
            self::assertSame($m[1], $script->getAttribute('nonce'), 'każdy skrypt inline ma nonce z nagłówka');
        }

        $html = (string) $client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html, 'brak zasobów z zewnętrznych domen');
        self::assertStringNotContainsString(' style="', $html, 'brak stylów inline (CSP style-src self)');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertResponseNotHasHeader('Strict-Transport-Security');
    }

    public function testHstsOnlyOverHttps(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/login');

        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');
    }

    public function testApiHasRestrictiveCsp(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/ping');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function testHealthEndpoints(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok","secure":false}', $client->getResponse()->getContent());

        $client->request('GET', 'https://localhost/health');
        self::assertSame('{"status":"ok","secure":true}', $client->getResponse()->getContent());

        $client->request('GET', '/health/db');
        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }
}
