<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\AdminFixtures;
use App\Tests\Functional\DeviceFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Baza testów panelu: zalogowany administrator.
 */
abstract class PanelTestCase extends WebTestCase
{
    use AdminFixtures;
    use ClockSensitiveTrait;
    use DeviceFixtures;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime('2026-11-20 12:00:00');
        $this->client = self::createClient();
        $this->client->loginUser($this->createAdmin());
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Wysyła formularz akcji (przycisk POST z CSRF) znaleziony na stronie.
     */
    protected function clickPostButton(string $pageUrl, string $buttonLabel): void
    {
        $crawler = $this->client->request('GET', $pageUrl);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton($buttonLabel)->form());
    }
}
