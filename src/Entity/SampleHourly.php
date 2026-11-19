<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rollup godzinny (min/avg/max/liczba próbek). Źródło wykresów 30 d / 1 rok.
 * Encja służy definicji schematu; operacje przez DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sample_hourly')]
#[ORM\Index(name: 'idx_sample_hourly_hour', columns: ['hour_ts'])]
class SampleHourly
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    /** Początek godziny, epoch UTC. */
    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $hourTs;

    #[ORM\Column]
    private float $minValue;

    #[ORM\Column]
    private float $avgValue;

    #[ORM\Column]
    private float $maxValue;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $sampleCount;

    public function __construct(Device $device, Metric $metric, int $hourTs, float $min, float $avg, float $max, int $count)
    {
        $this->device = $device;
        $this->metric = $metric;
        $this->hourTs = $hourTs;
        $this->minValue = $min;
        $this->avgValue = $avg;
        $this->maxValue = $max;
        $this->sampleCount = $count;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getHourTs(): int
    {
        return $this->hourTs;
    }

    public function getMinValue(): float
    {
        return $this->minValue;
    }

    public function getAvgValue(): float
    {
        return $this->avgValue;
    }

    public function getMaxValue(): float
    {
        return $this->maxValue;
    }

    public function getSampleCount(): int
    {
        return $this->sampleCount;
    }
}
