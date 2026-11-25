<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rollup godzinny (min/avg/max/liczba próbek). Źródło wykresów 30 d / 1 rok.
 * Encja służy definicji schematu; operacje przez DBAL. Jak w Sample: tylko klucz główny.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sample_hourly')]
class SampleHourly
{
    /** Identyfikator urządzenia, bez klucza obcego (próbki usuwa DeviceManager::delete). */
    #[ORM\Id]
    #[ORM\Column]
    private int $deviceId;

    /** Identyfikator z tabeli metric, bez klucza obcego (słownik metryk tylko rośnie). */
    #[ORM\Id]
    #[ORM\Column]
    private int $metricId;

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

    public function __construct(int $deviceId, int $metricId, int $hourTs, float $min, float $avg, float $max, int $count)
    {
        $this->deviceId = $deviceId;
        $this->metricId = $metricId;
        $this->hourTs = $hourTs;
        $this->minValue = $min;
        $this->avgValue = $avg;
        $this->maxValue = $max;
        $this->sampleCount = $count;
    }

    public function getDeviceId(): int
    {
        return $this->deviceId;
    }

    public function getMetricId(): int
    {
        return $this->metricId;
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
