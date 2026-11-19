<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Token agenta. W bazie tylko SHA-256 i krótki prefiks do identyfikacji w UI.
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_token')]
class DeviceToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 16)]
    private string $prefix;

    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Ustawiane przy rotacji: stary token działa do tego czasu. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(Device $device, string $prefix, string $tokenHash, \DateTimeImmutable $createdAt)
    {
        $this->device = $device;
        $this->prefix = $prefix;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function expireAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function isValidAt(\DateTimeImmutable $now): bool
    {
        if (null !== $this->revokedAt) {
            return false;
        }

        return null === $this->expiresAt || $this->expiresAt > $now;
    }
}
