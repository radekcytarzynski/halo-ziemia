<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Enum\OutboxStatus;
use App\Repository\OutboxMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kolejka powiadomień. Treść jest wyrenderowana w chwili zakolejkowania,
 * więc błąd SMTP nie gubi alarmu: wiadomość czeka na kolejny tick.
 */
#[ORM\Entity(repositoryClass: OutboxMessageRepository::class)]
#[ORM\Table(name: 'notification_outbox')]
#[ORM\Index(name: 'idx_outbox_status_next', columns: ['status', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_outbox_created', columns: ['created_at'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Recipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Device $device;

    #[ORM\Column(length: 16, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyText;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyHtml;

    #[ORM\Column(length: 8, enumType: OutboxStatus::class)]
    private OutboxStatus $status = OutboxStatus::Pending;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(
        ?Recipient $recipient,
        ?Device $device,
        NotificationChannel $channel,
        string $address,
        string $subject,
        string $bodyText,
        ?string $bodyHtml,
        \DateTimeImmutable $createdAt,
    ) {
        $this->recipient = $recipient;
        $this->device = $device;
        $this->channel = $channel;
        $this->address = $address;
        $this->subject = mb_substr($subject, 0, 255);
        $this->bodyText = $bodyText;
        $this->bodyHtml = $bodyHtml;
        $this->createdAt = $createdAt;
        $this->nextAttemptAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): ?Recipient
    {
        return $this->recipient;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBodyText(): string
    {
        return $this->bodyText;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): \DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->status = OutboxStatus::Sent;
        $this->sentAt = $now;
        $this->lastError = null;
    }

    public function markAttemptFailed(string $error, \DateTimeImmutable $nextAttemptAt, bool $giveUp): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 2000);
        $this->nextAttemptAt = $nextAttemptAt;
        $this->status = $giveUp ? OutboxStatus::Failed : OutboxStatus::Pending;
    }
}
