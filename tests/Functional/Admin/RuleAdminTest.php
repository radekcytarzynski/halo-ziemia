<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AlertRule;
use App\Entity\AlertState;
use App\Enum\Severity;
use App\Ingest\HeartbeatIngestor;
use App\Ingest\Payload\HeartbeatParser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class RuleAdminTest extends PanelTestCase
{
    public function testEditThresholds(): void
    {
        [$device] = $this->createDeviceWithToken();
        $rule = $this->em()->getRepository(AlertRule::class)->findOneBy(['device' => $device, 'target' => 'cpu.temp_c']);
        self::assertNotNull($rule);

        $crawler = $this->client->request('GET', '/rules/'.$rule->getId().'/edit');
        $this->client->submit($crawler->selectButton('Zapisz')->form([
            'alert_rule[warnThreshold]' => '65',
            'alert_rule[critThreshold]' => '75,5',
        ]));

        self::assertResponseRedirects('/devices/'.$device->getId().'#rules');
        $this->em()->clear();
        $rule = $this->em()->find(AlertRule::class, $rule->getId());
        self::assertNotNull($rule);
        self::assertSame(65.0, $rule->getWarnThreshold());
        self::assertSame(75.5, $rule->getCritThreshold());
    }

    public function testValidation(): void
    {
        [$device] = $this->createDeviceWithToken();
        $crawler = $this->client->request('GET', '/devices/'.$device->getId().'/rules/new');
        $this->client->submit($crawler->selectButton('Dodaj regułę')->form([
            'alert_rule[target]' => 'disk.*.*',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'najwyżej jeden znak „*”');
        self::assertSelectorTextContains('body', 'co najmniej jeden próg');
    }

    public function testAddCustomRule(): void
    {
        [$device] = $this->createDeviceWithToken();
        $crawler = $this->client->request('GET', '/devices/'.$device->getId().'/rules/new?target=ups.battery_pct');
        $this->client->submit($crawler->selectButton('Dodaj regułę')->form([
            'alert_rule[label]' => 'Bateria UPS',
            'alert_rule[warnThreshold]' => '50',
            'alert_rule[critThreshold]' => '20',
            'alert_rule[operator]' => 'lt',
        ]));

        self::assertResponseRedirects();
        $rule = $this->em()->getRepository(AlertRule::class)->findOneBy(['device' => $device, 'target' => 'ups.battery_pct']);
        self::assertSame('Bateria UPS', $rule?->getLabel());
    }

    public function testDisablingRuleClearsItsAlarmStates(): void
    {
        [$device, $token] = $this->createDeviceWithToken();
        $parsed = self::getContainer()->get(HeartbeatParser::class)->parse(['schema' => 1, 'checks' => [['name' => 'backup', 'status' => 'fail']]], new \DateTimeImmutable());
        self::getContainer()->get(HeartbeatIngestor::class)->ingest($token->token, $parsed, null);
        $rule = $this->em()->getRepository(AlertRule::class)->findOneBy(['device' => $device, 'target' => '*']);
        self::assertNotNull($rule);
        $states = $this->em()->getRepository(AlertState::class)->findBy(['rule' => $rule]);
        self::assertSame(Severity::Crit, $states[0]->getSeverity());

        $crawler = $this->client->request('GET', '/rules/'.$rule->getId().'/edit');
        $form = $crawler->selectButton('Zapisz')->form();
        $enabled = $form['alert_rule[enabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $enabled);
        $enabled->untick();
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame([], $this->em()->getRepository(AlertState::class)->findBy(['rule' => $rule]));
    }

    public function testDeleteRule(): void
    {
        [$device] = $this->createDeviceWithToken();
        $rule = $this->em()->getRepository(AlertRule::class)->findOneBy(['device' => $device, 'target' => 'cpu.temp_c']);
        self::assertNotNull($rule);
        $id = $rule->getId();

        $this->clickPostButton('/rules/'.$id.'/edit', 'Usuń regułę');

        self::assertResponseRedirects('/devices/'.$device->getId().'#rules');
        $this->em()->clear();
        self::assertNull($this->em()->find(AlertRule::class, $id));
    }
}
