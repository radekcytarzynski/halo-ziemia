<?php

declare(strict_types=1);

namespace App\Metrics;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Mapowanie kluczy metryk na identyfikatory; brakujące klucze dopisuje do słownika.
 */
final class MetricRegistry implements ResetInterface
{
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    public function idsFor(array $keys): array
    {
        $missing = array_values(array_diff($keys, array_keys($this->cache)));
        if ([] !== $missing) {
            $this->load($missing);
            $stillMissing = array_values(array_diff($missing, array_keys($this->cache)));
            if ([] !== $stillMissing) {
                $this->insert($stillMissing);
                $this->load($stillMissing);
            }
        }

        $result = [];
        foreach ($keys as $key) {
            if (!isset($this->cache[$key])) {
                throw new \RuntimeException(\sprintf('Nie udało się zarejestrować metryki "%s".', $key));
            }
            $result[$key] = $this->cache[$key];
        }

        return $result;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @param list<string> $keys
     */
    private function load(array $keys): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, metric_key FROM metric WHERE metric_key IN (?)',
            [$keys],
            [ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            $key = $row['metric_key'];
            $id = $row['id'];
            if (\is_string($key) && is_numeric($id)) {
                $this->cache[$key] = (int) $id;
            }
        }
    }

    /**
     * @param list<string> $keys
     */
    private function insert(array $keys): void
    {
        $placeholders = [];
        $params = [];
        foreach ($keys as $key) {
            $placeholders[] = '(?, ?)';
            $params[] = $key;
            $params[] = MetricUnit::guess($key);
        }

        // INSERT IGNORE: równoległy ingest mógł już dodać ten sam klucz.
        $this->connection->executeStatement(
            'INSERT IGNORE INTO metric (metric_key, unit) VALUES '.implode(', ', $placeholders),
            $params,
        );
    }
}
