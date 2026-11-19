<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Surowa próbka (1/min). Wąska tabela; zapis i odczyt przez DBAL (bulk insert, zapytania wykresów).
 * Encja służy definicji schematu. Czas jako epoch UTC w sekundach.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sample')]
#[ORM\Index(name: 'idx_sample_ts', columns: ['ts'])]
class Sample
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $ts;

    #[ORM\Column]
    private float $value;

    public function __construct(Device $device, Metric $metric, int $ts, float $value)
    {
        $this->device = $device;
        $this->metric = $metric;
        $this->ts = $ts;
        $this->value = $value;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getTs(): int
    {
        return $this->ts;
    }

    public function getValue(): float
    {
        return $this->value;
    }
}
