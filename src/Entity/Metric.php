<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MetricRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Słownik kluczy metryk, np. "disk./.used_pct", "smart.sda.temp_c".
 */
#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: 'metric')]
class Metric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** 190 znaków: limit długości indeksu unikalnego dla utf8mb4. */
    #[ORM\Column(name: 'metric_key', length: 190, unique: true)]
    private string $key;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unit = null;

    public function __construct(string $key, ?string $unit = null)
    {
        $this->key = $key;
        $this->unit = $unit;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }
}
