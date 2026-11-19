<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Repository\RecipientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RecipientRepository::class)]
#[ORM\Table(name: 'recipient')]
class Recipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(length: 16, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    /** Adres w kanale (e-mail; dla przyszłych kanałów np. chat_id). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $address;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, NotificationChannel $channel, string $address, \DateTimeImmutable $createdAt)
    {
        $this->name = $name;
        $this->channel = $channel;
        $this->address = $address;
        $this->createdAt = $createdAt;
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

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function setChannel(NotificationChannel $channel): void
    {
        $this->channel = $channel;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
