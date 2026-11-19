<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Severity;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kto (recipient) dostaje powiadomienia o którym urządzeniu (null = wszystkie) i od jakiej severity.
 * DOWN traktowany jest jak CRIT przy porównaniu z minSeverity.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Device $device;

    #[ORM\Column(length: 8, enumType: Severity::class)]
    private Severity $minSeverity = Severity::Warn;

    #[ORM\Column]
    private bool $notifyRecovery = true;

    public function __construct(Recipient $recipient, ?Device $device)
    {
        $this->recipient = $recipient;
        $this->device = $device;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): Recipient
    {
        return $this->recipient;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): void
    {
        $this->device = $device;
    }

    public function getMinSeverity(): Severity
    {
        return $this->minSeverity;
    }

    public function setMinSeverity(Severity $minSeverity): void
    {
        $this->minSeverity = $minSeverity;
    }

    public function isNotifyRecovery(): bool
    {
        return $this->notifyRecovery;
    }

    public function setNotifyRecovery(bool $notifyRecovery): void
    {
        $this->notifyRecovery = $notifyRecovery;
    }
}
