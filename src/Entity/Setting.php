<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ustawienia globalne i stan systemu (klucz → wartość JSON), np. "reminder_interval_s", "last_tick_at".
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::JSON)]
    private int|float|string|bool|null $value;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, int|float|string|bool|null $value, \DateTimeImmutable $updatedAt)
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedAt = $updatedAt;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): int|float|string|bool|null
    {
        return $this->value;
    }

    public function setValue(int|float|string|bool|null $value, \DateTimeImmutable $now): void
    {
        $this->value = $value;
        $this->updatedAt = $now;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
