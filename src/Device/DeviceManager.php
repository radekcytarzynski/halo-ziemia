<?php

declare(strict_types=1);

namespace App\Device;

use App\Alerting\DefaultRules;
use App\Entity\Device;
use App\Enum\EventType;
use App\Repository\DeviceRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class DeviceManager
{
    public function __construct(
        private DeviceRepository $devices,
        private EntityManagerInterface $entityManager,
        private EventRecorder $events,
        private ClockInterface $clock,
    ) {
    }

    public function suggestSlug(string $name): string
    {
        $slug = (new AsciiSlugger('pl'))->slug($name)->lower()->toString();
        $slug = trim($slug, '-');

        return '' === $slug ? 'urzadzenie' : mb_substr($slug, 0, 100);
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->devices->findOneBy(['slug' => $slug]);
    }

    public function create(string $name, ?string $slug = null): Device
    {
        return $this->register(new Device($name, $slug ?? $this->suggestSlug($name), $this->clock->now()));
    }

    /**
     * Zapis nowego urządzenia razem z domyślnymi regułami.
     */
    public function register(Device $device): Device
    {
        if ('' === $device->getSlug()) {
            $device->setSlug($this->suggestSlug($device->getName()));
        }

        $this->entityManager->persist($device);
        foreach (DefaultRules::createFor($device) as $rule) {
            $this->entityManager->persist($rule);
        }
        $this->events->record($device, EventType::ConfigChange, \sprintf('Dodano urządzenie „%s”', $device->getName()));
        $this->entityManager->flush();

        return $device;
    }

    public function update(Device $device): void
    {
        $device->touch($this->clock->now());
        $this->events->record($device, EventType::ConfigChange, 'Zmieniono konfigurację urządzenia');
        $this->entityManager->flush();
    }

    public function setEnabled(Device $device, bool $enabled): void
    {
        $device->setEnabled($enabled);
        $device->touch($this->clock->now());
        $this->events->record($device, EventType::ConfigChange, $enabled ? 'Włączono monitorowanie' : 'Wyłączono monitorowanie');
        $this->entityManager->flush();
    }

    public function mute(Device $device, \DateTimeImmutable $until): void
    {
        $device->setMutedUntil($until);
        $this->events->record($device, EventType::Maintenance, \sprintf('Tryb serwisowy do %s UTC', $until->format('Y-m-d H:i')));
        $this->entityManager->flush();
    }

    public function unmute(Device $device): void
    {
        $device->setMutedUntil(null);
        $this->events->record($device, EventType::Maintenance, 'Zakończono tryb serwisowy');
        $this->entityManager->flush();
    }

    /**
     * Usunięcie urządzenia razem z danymi (kaskadowo). W dzienniku zostaje wpis globalny.
     */
    public function delete(Device $device): void
    {
        $name = $device->getName();
        $this->entityManager->remove($device);
        $this->events->record(null, EventType::ConfigChange, \sprintf('Usunięto urządzenie „%s” wraz z danymi', $name));
        $this->entityManager->flush();
    }
}
