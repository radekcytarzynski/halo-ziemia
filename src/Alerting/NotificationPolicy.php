<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Enum\Severity;

/**
 * Kiedy wysłać powiadomienie dla podmiotu (heartbeat / reguła).
 *
 * Porównujemy bieżący stan z ostatnio ZGŁOSZONYM (notifiedSeverity, null = OK):
 *  - różne → powiadomienie o zmianie (to obsługuje też nadrabianie po trybie serwisowym),
 *  - takie same, problem trwa, minął interwał od ostatniego powiadomienia → przypomnienie.
 * W trybie serwisowym nic nie wysyłamy (stan zgłoszony się nie zmienia, więc po wyciszeniu zostanie nadrobiony).
 */
final class NotificationPolicy
{
    public function decide(
        Severity $severity,
        ?Severity $notifiedSeverity,
        ?\DateTimeImmutable $lastNotifiedAt,
        int $reminderIntervalSeconds,
        bool $muted,
        \DateTimeImmutable $now,
    ): NotificationKind {
        if ($muted) {
            return NotificationKind::None;
        }

        if ($severity !== ($notifiedSeverity ?? Severity::Ok)) {
            return NotificationKind::Change;
        }

        if (Severity::Ok === $severity || $reminderIntervalSeconds <= 0 || null === $lastNotifiedAt) {
            return NotificationKind::None;
        }

        $due = $lastNotifiedAt->modify(\sprintf('+%d seconds', $reminderIntervalSeconds));

        return $now >= $due ? NotificationKind::Reminder : NotificationKind::None;
    }
}
