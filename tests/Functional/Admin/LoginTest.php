<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use App\Tests\Functional\AdminFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginTest extends WebTestCase
{
    use AdminFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->createAdmin();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageRenders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[type=submit]', 'Zaloguj');
        self::assertSelectorExists('input[name=_csrf_token]');
    }

    public function testSuccessfulLoginShowsDashboardAndRecordsLastLogin(): void
    {
        $this->submitLogin(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pulpit');

        $repository = self::getContainer()->get(AdminUserRepository::class);
        $user = $repository->findOneByEmail(self::ADMIN_EMAIL);
        self::assertInstanceOf(AdminUser::class, $user);
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->submitLogin(self::ADMIN_EMAIL, 'wrong password!!');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertSelectorTextContains('.flash-error', 'Nieprawidłowe');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
            '_csrf_token' => 'invalid',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testLogout(): void
    {
        $this->submitLogin(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testSessionCookieOptionsAreHardened(): void
    {
        // W testach sesja jest mockowana, więc sprawdzamy konfigurację przekazywaną do natywnej sesji PHP.
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertSame('hz_session', $options['name']);
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('lax', $options['cookie_samesite']);
        self::assertSame('auto', $options['cookie_secure']);
    }

    private function submitLogin(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Zaloguj')->form([
            'email' => $email,
            'password' => $password,
        ]);
        $this->client->submit($form);
    }
}
