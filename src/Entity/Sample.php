<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Surowa próbka (1/min). Wąska tabela; zapis i odczyt przez DBAL (bulk insert, zapytania wykresów).
 * Encja służy definicji schematu. Czas jako epoch UTC w sekundach.
 *
 * Celowo tylko klucz główny (device_id, metric_id, ts), bez kluczy obcych i indeksów dodatkowych: każdy indeks
 * to kilkadziesiąt bajtów na wiersz, a wierszy jest ~45 na urządzenie na minutę. Wszystkie zapytania (wykresy,
 * rollup, retencja) idą po parze urządzenie–metryka, czyli po początku klucza głównego.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sample')]
class Sample
{
    /** Identyfikator urządzenia, bez klucza obcego (próbki usuwa DeviceManager::delete). */
    #[ORM\Id]
    #[ORM\Column]
    private int $deviceId;

    /** Identyfikator z tabeli metric, bez klucza obcego (słownik metryk tylko rośnie). */
    #[ORM\Id]
    #[ORM\Column]
    private int $metricId;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $ts;

    #[ORM\Column]
    private float $value;

    public function __construct(int $deviceId, int $metricId, int $ts, float $value)
    {
        $this->deviceId = $deviceId;
        $this->metricId = $metricId;
        $this->ts = $ts;
        $this->value = $value;
    }

    public function getDeviceId(): int
    {
        return $this->deviceId;
    }

    public function getMetricId(): int
    {
        return $this->metricId;
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
