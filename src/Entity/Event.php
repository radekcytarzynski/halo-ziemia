<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventType;
use App\Enum\Severity;
use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wpis osi czasu / dziennika: przejścia stanów, powiadomienia, zmiany konfiguracji.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_device_created', columns: ['device_id', 'created_at'])]
#[ORM\Index(name: 'idx_event_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_event_type_created', columns: ['type', 'created_at'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Device $device;

    #[ORM\Column(length: 24, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(length: 8, nullable: true, enumType: Severity::class)]
    private ?Severity $severity;

    #[ORM\Column(length: 500)]
    private string $message;

    /** @var array<string, scalar|null>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, scalar|null>|null $context
     */
    public function __construct(
        ?Device $device,
        EventType $type,
        string $message,
        \DateTimeImmutable $createdAt,
        ?Severity $severity = null,
        ?array $context = null,
    ) {
        $this->device = $device;
        $this->type = $type;
        $this->message = mb_substr($message, 0, 500);
        $this->createdAt = $createdAt;
        $this->severity = $severity;
        $this->context = $context;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function getSeverity(): ?Severity
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
