<?php

declare(strict_types=1);

namespace App\Device;

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
        $device = new Device($name, $slug ?? $this->suggestSlug($name), $this->clock->now());

        $this->entityManager->persist($device);
        $this->events->record($device, EventType::ConfigChange, \sprintf('Dodano urządzenie „%s”', $name));
        $this->entityManager->flush();

        return $device;
    }
}
