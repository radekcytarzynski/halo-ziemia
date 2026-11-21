<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Severity;
use App\Repository\IncidentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Okres trwania problemu (od wyjścia z OK do powrotu do OK) dla jednego podmiotu.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incident')]
#[ORM\Index(name: 'idx_incident_device_opened', columns: ['device_id', 'opened_at'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 8, enumType: Severity::class)]
    private Severity $maxSeverity;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(Device $device, string $subject, string $title, Severity $severity, \DateTimeImmutable $openedAt)
    {
        $this->device = $device;
        $this->subject = $subject;
        $this->title = mb_substr($title, 0, 255);
        $this->maxSeverity = $severity;
        $this->openedAt = $openedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMaxSeverity(): Severity
    {
        return $this->maxSeverity;
    }

    public function escalate(Severity $severity): void
    {
        if ($severity->isWorseThan($this->maxSeverity)) {
            $this->maxSeverity = $severity;
        }
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function close(\DateTimeImmutable $closedAt): void
    {
        $this->closedAt ??= $closedAt;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }
}
