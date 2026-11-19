<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CheckStatus;
use App\Repository\CheckResultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ostatni wynik checka agenta (np. "backup") per urządzenie.
 */
#[ORM\Entity(repositoryClass: CheckResultRepository::class)]
#[ORM\Table(name: 'check_result')]
#[ORM\UniqueConstraint(name: 'uniq_check_device_name', columns: ['device_id', 'name'])]
class CheckResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 8, enumType: CheckStatus::class)]
    private CheckStatus $status;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct(Device $device, string $name, CheckStatus $status, \DateTimeImmutable $receivedAt)
    {
        $this->device = $device;
        $this->name = $name;
        $this->status = $status;
        $this->receivedAt = $receivedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): CheckStatus
    {
        return $this->status;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function update(CheckStatus $status, ?string $message, ?\DateTimeImmutable $lastSuccessAt, \DateTimeImmutable $receivedAt): void
    {
        $this->status = $status;
        $this->message = $message;
        $this->lastSuccessAt = $lastSuccessAt;
        $this->receivedAt = $receivedAt;
    }
}
