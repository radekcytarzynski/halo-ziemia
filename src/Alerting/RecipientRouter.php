<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

/**
 * Wybór odbiorców dla zmian stanu.
 *
 * Każdy odbiorca „widzi” stan tylko od swojej minimalnej severity (poniżej = OK).
 * Odbiorca dostaje pozycję, gdy jego widziany stan się zmienił:
 *  - na nie-OK → problem,
 *  - na OK → odzyskanie (tylko z notifyRecovery).
 * Dzięki temu np. subskrybent „od CRIT” dostanie „odzyskano” po spadku CRIT → WARN.
 * Wiele subskrypcji tego samego odbiorcy łączy się (wygrywa najniższy próg / włączone odzyskania).
 */
final class RecipientRouter
{
    /**
     * @param list<RouteTarget>                               $targets
     * @param array<int, array{from: Severity, to: Severity}> $changes indeks zmiany → stany
     *
     * @return array<int, array{problems: list<int>, recoveries: list<int>}> recipientId → indeksy zmian
     */
    public function routeChanges(array $targets, array $changes): array
    {
        $routes = [];
        foreach ($this->mergeTargets($targets) as $target) {
            $problems = [];
            $recoveries = [];
            foreach ($changes as $index => $change) {
                $from = self::visible($change['from'], $target->minSeverity);
                $to = self::visible($change['to'], $target->minSeverity);
                if ($from === $to) {
                    continue;
                }
                if (Severity::Ok !== $to) {
                    $problems[] = $index;
                } elseif ($target->notifyRecovery) {
                    $recoveries[] = $index;
                }
            }

            if ([] !== $problems || [] !== $recoveries) {
                $routes[$target->recipientId] = ['problems' => $problems, 'recoveries' => $recoveries];
            }
        }

        return $routes;
    }

    /**
     * @param list<RouteTarget>    $targets
     * @param array<int, Severity> $severities indeks → bieżący (trwający) stan
     *
     * @return array<int, list<int>> recipientId → indeksy przypomnień
     */
    public function routeReminders(array $targets, array $severities): array
    {
        $routes = [];
        foreach ($this->mergeTargets($targets) as $target) {
            $items = [];
            foreach ($severities as $index => $severity) {
                if (Severity::Ok !== self::visible($severity, $target->minSeverity)) {
                    $items[] = $index;
                }
            }
            if ([] !== $items) {
                $routes[$target->recipientId] = $items;
            }
        }

        return $routes;
    }

    public static function visible(Severity $severity, Severity $minSeverity): Severity
    {
        return $severity->isAtLeast($minSeverity) ? $severity : Severity::Ok;
    }

    /**
     * @param list<RouteTarget> $targets
     *
     * @return array<int, RouteTarget>
     */
    private function mergeTargets(array $targets): array
    {
        $merged = [];
        foreach ($targets as $target) {
            $existing = $merged[$target->recipientId] ?? null;
            if (null === $existing) {
                $merged[$target->recipientId] = $target;
                continue;
            }

            $merged[$target->recipientId] = new RouteTarget(
                $target->recipientId,
                $existing->minSeverity->isWorseThan($target->minSeverity) ? $target->minSeverity : $existing->minSeverity,
                $existing->notifyRecovery || $target->notifyRecovery,
            );
        }

        return $merged;
    }
}
