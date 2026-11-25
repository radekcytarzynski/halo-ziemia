<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeviceStatus;
use App\Repository\DeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\Index(name: 'idx_device_enabled_seen', columns: ['enabled', 'last_seen_at'])]
#[UniqueEntity(fields: ['slug'], message: 'Urządzenie o tym identyfikatorze już istnieje.')]
class Device
{
    public const int DEFAULT_INTERVAL_SECONDS = 60;
    public const int DEFAULT_GRACE_SECONDS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/', message: 'Dozwolone: małe litery, cyfry i myślnik.')]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Na przyszłość: grupowanie po lokalizacji. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $location = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    #[Assert\Range(min: 10, max: 86400)]
    private int $expectedIntervalSeconds = self::DEFAULT_INTERVAL_SECONDS;

    /** null = wartość globalna z ustawień. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 1000)]
    private ?int $missedIntervalsThreshold = null;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 86400)]
    private int $graceSeconds = self::DEFAULT_GRACE_SECONDS;

    #[ORM\Column(length: 16, enumType: DeviceStatus::class)]
    private DeviceStatus $status = DeviceStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    /** Tryb serwisowy: do tego czasu powiadomienia są wstrzymane (zdarzenia nadal zapisywane). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastSnapshot = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $lastAgentVersion = null;

    /** Publiczny adres, z którego przyszedł ostatni heartbeat (adresy lokalne są w snapshocie: "ip"). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastIp = null;

    /**
     * Nazwy dysków nadane w panelu: identyfikator (punkt montowania, dysk SMART, macierz RAID) => nazwa.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $diskLabels = null;

    /** Punkt montowania pokazywany na karcie dashboardu; null = najbardziej zajęty dysk. */
    #[ORM\Column(length: 151, nullable: true)]
    private ?string $dashboardDisk = null;

    /** Klucz metryki miniwykresu na karcie dashboardu; null = automatycznie (temperatura CPU, load, dysk /). */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $dashboardChart = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug, \DateTimeImmutable $createdAt)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getExpectedIntervalSeconds(): int
    {
        return $this->expectedIntervalSeconds;
    }

    public function setExpectedIntervalSeconds(int $expectedIntervalSeconds): void
    {
        $this->expectedIntervalSeconds = $expectedIntervalSeconds;
    }

    public function getMissedIntervalsThreshold(): ?int
    {
        return $this->missedIntervalsThreshold;
    }

    public function setMissedIntervalsThreshold(?int $missedIntervalsThreshold): void
    {
        $this->missedIntervalsThreshold = $missedIntervalsThreshold;
    }

    public function getGraceSeconds(): int
    {
        return $this->graceSeconds;
    }

    public function setGraceSeconds(int $graceSeconds): void
    {
        $this->graceSeconds = $graceSeconds;
    }

    public function getStatus(): DeviceStatus
    {
        return $this->status;
    }

    public function setStatus(DeviceStatus $status, \DateTimeImmutable $now): void
    {
        if ($status !== $this->status) {
            $this->status = $status;
            $this->statusChangedAt = $now;
        }
    }

    public function getStatusChangedAt(): ?\DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function setMutedUntil(?\DateTimeImmutable $mutedUntil): void
    {
        $this->mutedUntil = $mutedUntil;
    }

    public function isMutedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->mutedUntil && $this->mutedUntil > $now;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastSnapshot(): ?array
    {
        return $this->lastSnapshot;
    }

    /**
     * @param array<string, mixed>|null $lastSnapshot
     */
    public function setLastSnapshot(?array $lastSnapshot): void
    {
        $this->lastSnapshot = $lastSnapshot;
    }

    public function getLastAgentVersion(): ?string
    {
        return $this->lastAgentVersion;
    }

    public function setLastAgentVersion(?string $lastAgentVersion): void
    {
        $this->lastAgentVersion = $lastAgentVersion;
    }

    public function getLastIp(): ?string
    {
        return $this->lastIp;
    }

    public function setLastIp(?string $lastIp): void
    {
        $this->lastIp = $lastIp;
    }

    /**
     * Adresy lokalne zgłoszone przez agenta (hostname -I), w kolejności z urządzenia.
     *
     * @return list<string>
     */
    public function getLocalIps(): array
    {
        $ip = $this->lastSnapshot['ip'] ?? null;
        if (\is_string($ip)) {
            $ip = preg_split('/[\s,]+/', $ip, -1, \PREG_SPLIT_NO_EMPTY);
        }
        if (!\is_array($ip)) {
            return [];
        }

        return array_values(array_filter($ip, static fn (mixed $item): bool => \is_string($item) && '' !== $item));
    }

    /**
     * Główny adres lokalny: pierwszy IPv4 (adresy IPv6 bywają długie i zmienne), inaczej pierwszy dowolny.
     */
    public function getPrimaryLocalIp(): ?string
    {
        $ips = $this->getLocalIps();
        foreach ($ips as $ip) {
            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $ips[0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getDiskLabels(): array
    {
        return $this->diskLabels ?? [];
    }

    /**
     * @param array<string, string> $labels
     */
    public function setDiskLabels(array $labels): void
    {
        ksort($labels);
        $this->diskLabels = [] === $labels ? null : $labels;
    }

    public function getDiskLabel(string $id): ?string
    {
        return $this->diskLabels[$id] ?? null;
    }

    /**
     * Opis dysku do tytułów alarmów i etykiet: "Backup, /mnt/backup" albo sam identyfikator, gdy bez nazwy.
     */
    public function describeDisk(string $id): string
    {
        $label = $this->getDiskLabel($id);

        return null === $label ? $id : $label.', '.$id;
    }

    public function getDashboardDisk(): ?string
    {
        return $this->dashboardDisk;
    }

    public function setDashboardDisk(?string $dashboardDisk): void
    {
        $this->dashboardDisk = $dashboardDisk;
    }

    public function getDashboardChart(): ?string
    {
        return $this->dashboardChart;
    }

    public function setDashboardChart(?string $dashboardChart): void
    {
        $this->dashboardChart = $dashboardChart;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
