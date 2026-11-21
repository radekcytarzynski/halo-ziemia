<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Recipient;
use App\Entity\Subscription;
use App\Enum\Severity;

final class RecipientAdminTest extends PanelTestCase
{
    public function testCreateRecipientWithDefaultSubscriptionAndManageSubscriptions(): void
    {
        [$device] = $this->createDeviceWithToken();

        $crawler = $this->client->request('GET', '/recipients/new');
        $this->client->submit($crawler->selectButton('Dodaj')->form([
            'recipient[name]' => 'Radek',
            'recipient[address]' => 'radek@example.test',
        ]));

        $recipient = $this->em()->getRepository(Recipient::class)->findOneBy(['address' => 'radek@example.test']);
        self::assertNotNull($recipient);
        self::assertResponseRedirects('/recipients/'.$recipient->getId());
        $subscriptions = $this->em()->getRepository(Subscription::class)->findBy(['recipient' => $recipient]);
        self::assertCount(1, $subscriptions);
        self::assertNull($subscriptions[0]->getDevice());

        $crawler = $this->client->request('GET', '/recipients/'.$recipient->getId());
        $this->client->submit($crawler->filter('form[action$="/subscriptions"]')->form([
            'subscription[device]' => (string) $device->getId(),
            'subscription[minSeverity]' => 'crit',
        ]));
        self::assertResponseRedirects('/recipients/'.$recipient->getId());
        $sub = $this->em()->getRepository(Subscription::class)->findOneBy(['recipient' => $recipient, 'device' => $device]);
        self::assertSame(Severity::Crit, $sub?->getMinSeverity());

        $crawler = $this->client->request('GET', '/recipients');
        self::assertStringContainsString('NAS Debian · od CRIT', $crawler->filter('table')->text());
    }

    public function testSendTestEmail(): void
    {
        $recipient = new Recipient('Radek', \App\Enum\NotificationChannel::Email, 'radek@example.test', new \DateTimeImmutable());
        $this->em()->persist($recipient);
        $this->em()->flush();

        $this->clickPostButton('/recipients/'.$recipient->getId(), 'Wyślij testowy mail');

        self::assertEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Wysłano testowy e-mail');
    }

    public function testInvalidEmailRejected(): void
    {
        $crawler = $this->client->request('GET', '/recipients/new');
        $this->client->submit($crawler->selectButton('Dodaj')->form(['recipient[name]' => 'X', 'recipient[address]' => 'to-nie-email']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteRecipient(): void
    {
        $recipient = new Recipient('Radek', \App\Enum\NotificationChannel::Email, 'radek@example.test', new \DateTimeImmutable());
        $this->em()->persist($recipient);
        $this->em()->flush();
        $id = $recipient->getId();

        $this->clickPostButton('/recipients/'.$id, 'Usuń odbiorcę');

        self::assertResponseRedirects('/recipients');
        $this->em()->clear();
        self::assertNull($this->em()->find(Recipient::class, $id));
    }
}
