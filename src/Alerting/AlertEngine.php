<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Entity\AlertRule;
use App\Entity\AlertState;
use App\Entity\Device;
use App\Entity\Incident;
use App\Enum\EventType;
use App\Enum\RuleSource;
use App\Enum\Severity;
use App\Ingest\Payload\ParsedHeartbeat;
use App\Metrics\MetricUnit;
use App\Notification\NotificationDispatcher;
use App\Repository\AlertRuleRepository;
use App\Repository\AlertStateRepository;
use App\Repository\DeviceRepository;
use App\Settings\Settings;
use App\Timeline\EventRecorder;
use App\Twig\Formatter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orkiestracja silnika alarmów: łączy czyste serwisy (ocena reguł, maszyna stanów,
 * polityka powiadomień, agregacja statusu) z bazą i outboxem.
 *
 *  - onHeartbeat(): przy odbiorze heartbeatu – heartbeat OK, reguły metryk i checków,
 *  - tick(): z crona – wykrywanie DOWN, przestarzałe stany, przypomnienia, nadrabianie po wyciszeniu.
 */
final readonly class AlertEngine
{
    /** Stan nie-OK metryki, której agent nie raportuje od tylu sekund, jest zamykany (np. odmontowany dysk). */
    public const int STALE_AFTER_SECONDS = 86400;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeviceRepository $devices,
        private AlertRuleRepository $rules,
        private AlertStateRepository $states,
        private RuleEvaluator $ruleEvaluator,
        private StateMachine $stateMachine,
        private HeartbeatEvaluator $heartbeatEvaluator,
        private NotificationPolicy $notificationPolicy,
        private StatusAggregator $statusAggregator,
        private NotificationDispatcher $dispatcher,
        private EventRecorder $events,
        private Settings $settings,
    ) {
    }

    public function onHeartbeat(Device $device, ?ParsedHeartbeat $heartbeat, \DateTimeImmutable $now): void
    {
        $states = $this->states->findForDeviceIndexed($device);

        if ($device->isEnabled()) {
            $this->observe($device, $this->heartbeatState($device, $states, $now), Severity::Ok, 1, null, 'Kontakt z agentem', $now);

            if (null !== $heartbeat) {
                foreach ($this->rules->findEnabledForDevice($device) as $rule) {
                    $this->evaluateRule($device, $rule, $heartbeat, $states, $now);
                }
            }
        }

        $this->finalize($device, $states, $now);
        $this->entityManager->flush();
    }

    public function tick(\DateTimeImmutable $now): TickResult
    {
        $evaluated = $down = $queued = 0;

        foreach ($this->devices->findAll() as $device) {
            $states = $this->states->findForDeviceIndexed($device);
            $lastSeenAt = $device->getLastSeenAt();

            if ($device->isEnabled() && null !== $lastSeenAt) {
                ++$evaluated;
                $threshold = $device->getMissedIntervalsThreshold() ?? $this->settings->missedIntervalsThreshold();
                $isDown = $this->heartbeatEvaluator->isDown($lastSeenAt, $device->getExpectedIntervalSeconds(), $threshold, $device->getGraceSeconds(), $now);

                if ($isDown) {
                    ++$down;
                    $silence = $now->getTimestamp() - $lastSeenAt->getTimestamp();
                    $detail = \sprintf('Brak kontaktu od %s (oczekiwano co %s)', Formatter::duration($silence), Formatter::duration($device->getExpectedIntervalSeconds()));
                    $this->observe($device, $this->heartbeatState($device, $states, $now), Severity::Down, 1, (float) $silence, $detail, $now);
                } else {
                    $this->closeStaleStates($device, $states, $now);
                }
            }

            $queued += $this->finalize($device, $states, $now);
            $this->entityManager->flush();
        }

        return new TickResult($evaluated, $down, $queued);
    }

    /**
     * @param array<string, AlertState> $states
     */
    private function evaluateRule(Device $device, AlertRule $rule, ParsedHeartbeat $heartbeat, array &$states, \DateTimeImmutable $now): void
    {
        if (RuleSource::Metric === $rule->getSource()) {
            foreach (RuleMatcher::matchAll($rule->getTarget(), array_map('strval', array_keys($heartbeat->metrics))) as $key => $captured) {
                $value = $heartbeat->metrics[$key];
                $state = $this->ruleState($device, $rule, $key, $captured, $states, $now);
                $observed = $this->ruleEvaluator->evaluate($rule->getOperator(), $rule->getWarnThreshold(), $rule->getCritThreshold(), $rule->getHysteresis(), $value, $state->getSeverity());
                $this->observe($device, $state, $observed, $rule->getConsecutiveSamples(), $value, $this->metricDetail($rule, $key, $value), $now);
            }

            return;
        }

        foreach ($heartbeat->checks as $check) {
            $captured = RuleMatcher::match($rule->getTarget(), $check->name);
            if (null === $captured) {
                continue;
            }
            $state = $this->ruleState($device, $rule, $check->name, RuleMatcher::isWildcard($rule->getTarget()) ? $check->name : '', $states, $now);
            $detail = $check->message ?? 'status: '.$check->status->value;
            $this->observe($device, $state, $check->status->toSeverity(), $rule->getConsecutiveSamples(), null, $detail, $now);
        }
    }

    /**
     * Zastosowanie obserwacji: anty-flapping, przejście stanu, incydent, zdarzenie na osi czasu.
     */
    private function observe(Device $device, AlertState $state, Severity $observed, int $required, ?float $value, ?string $detail, \DateTimeImmutable $now): void
    {
        $decision = $this->stateMachine->observe($state->getSeverity(), $state->getCandidateSeverity(), $state->getCandidateCount(), $observed, $required);
        $state->setCandidate($decision->candidate, $decision->candidateCount);
        $state->setLastObservation($value, $detail, $now);

        if (!$decision->changed) {
            return;
        }

        $from = $state->getSeverity();
        $to = $decision->severity;
        $state->setSeverity($to, $now);
        $this->updateIncident($device, $state, $from, $to, $now);

        $message = \sprintf('%s: %s → %s', $state->getTitle(), $from->label(), $to->label());
        if (null !== $detail) {
            $message .= ' – '.$detail;
        }
        if ($device->isMutedAt($now)) {
            $message .= ' (tryb serwisowy – bez powiadomień)';
        }
        $this->events->record($device, EventType::StateChange, $message, $to, [
            'subject' => $state->getSubject(),
            'from' => $from->value,
            'to' => $to->value,
            'value' => $value,
        ], $now);
    }

    private function updateIncident(Device $device, AlertState $state, Severity $from, Severity $to, \DateTimeImmutable $now): void
    {
        $incident = $state->getIncident();

        if (Severity::Ok === $to) {
            $incident?->close($now);
            $state->setIncident(null);

            return;
        }

        if (Severity::Ok === $from || null === $incident) {
            $incident = new Incident($device, $state->getSubject(), $state->getTitle(), $to, $now);
            $this->entityManager->persist($incident);
            $state->setIncident($incident);

            return;
        }

        $incident->escalate($to);
    }

    /**
     * Powiadomienia (zmiany, nadrabianie, przypomnienia) i status urządzenia.
     *
     * @param array<string, AlertState> $states
     *
     * @return int liczba zakolejkowanych wiadomości
     */
    private function finalize(Device $device, array $states, \DateTimeImmutable $now): int
    {
        $relevant = array_values(array_filter($states, static fn (AlertState $s): bool => null === $s->getRule() || $s->getRule()->isEnabled()));
        $queued = 0;

        if ($device->isEnabled()) {
            $muted = $device->isMutedAt($now);
            $interval = $this->settings->reminderIntervalSeconds();
            $changes = $changedStates = $reminders = $reminderStates = [];

            foreach ($relevant as $state) {
                $kind = $this->notificationPolicy->decide($state->getSeverity(), $state->getNotifiedSeverity(), $state->getLastNotifiedAt(), $interval, $muted, $now);
                $item = new AlertItem($state->getTitle(), $state->getNotifiedSeverity() ?? Severity::Ok, $state->getSeverity(), $state->getLastMessage(), $state->getSince());

                if (NotificationKind::Change === $kind) {
                    $changes[] = $item;
                    $changedStates[] = $state;
                } elseif (NotificationKind::Reminder === $kind) {
                    $reminders[] = $item;
                    $reminderStates[] = $state;
                }
            }

            $queued += $this->dispatcher->dispatchChanges($device, $changes, $now);
            foreach ($changedStates as $state) {
                $state->markNotified($state->getSeverity(), $now, false);
            }

            $queued += $this->dispatcher->dispatchReminders($device, $reminders, $now);
            foreach ($reminderStates as $state) {
                $state->markNotified($state->getSeverity(), $now, true);
                $this->events->record($device, EventType::Reminder, \sprintf('Przypomnienie #%d: %s (%s)', $state->getReminderCount(), $state->getTitle(), $state->getSeverity()->label()), $state->getSeverity(), at: $now);
            }
        }

        $status = $this->statusAggregator->aggregate(
            $device->isEnabled(),
            null !== $device->getLastSeenAt(),
            array_map(static fn (AlertState $s): Severity => $s->getSeverity(), $relevant),
        );
        $device->setStatus($status, $now);

        return $queued;
    }

    /**
     * Metryka, która przestała być raportowana (np. odłączony dysk), nie może wisieć w alarmie w nieskończoność.
     * Zamykamy takie stany tylko, gdy urządzenie samo się melduje (inaczej to DOWN, nie brak metryki).
     *
     * @param array<string, AlertState> $states
     */
    private function closeStaleStates(Device $device, array $states, \DateTimeImmutable $now): void
    {
        $threshold = $now->modify(\sprintf('-%d seconds', self::STALE_AFTER_SECONDS));
        foreach ($states as $state) {
            $observedAt = $state->getLastObservedAt();
            if (null === $state->getRule() || Severity::Ok === $state->getSeverity() || null === $observedAt || $observedAt > $threshold) {
                continue;
            }

            $this->observe($device, $state, Severity::Ok, 1, null, 'Brak danych – metryka nie jest już raportowana', $now);
        }
    }

    /**
     * @param array<string, AlertState> $states
     */
    private function heartbeatState(Device $device, array &$states, \DateTimeImmutable $now): AlertState
    {
        return $states[AlertState::SUBJECT_HEARTBEAT] ??= $this->createState($device, AlertState::SUBJECT_HEARTBEAT, 'Kontakt z agentem', null, $now);
    }

    /**
     * @param array<string, AlertState> $states
     */
    private function ruleState(Device $device, AlertRule $rule, string $key, string $captured, array &$states, \DateTimeImmutable $now): AlertState
    {
        $subject = $rule->subjectKey(RuleMatcher::isWildcard($rule->getTarget()) ? $key : null);
        $title = $rule->titleFor($captured);

        $state = $states[$subject] ??= $this->createState($device, $subject, $title, $rule, $now);
        if ($state->getTitle() !== $title) {
            $state->setTitle($title);
        }

        return $state;
    }

    private function createState(Device $device, string $subject, string $title, ?AlertRule $rule, \DateTimeImmutable $now): AlertState
    {
        $state = new AlertState($device, $subject, $title, $rule, $now);
        $this->entityManager->persist($state);

        return $state;
    }

    private function metricDetail(AlertRule $rule, string $key, float $value): string
    {
        $unit = MetricUnit::guess($key);
        $thresholds = [];
        foreach (['WARN' => $rule->getWarnThreshold(), 'CRIT' => $rule->getCritThreshold()] as $label => $threshold) {
            if (null !== $threshold) {
                $thresholds[] = \sprintf('%s %s %s', $label, $rule->getOperator()->symbol(), Formatter::metric($threshold, $unit));
            }
        }

        $detail = Formatter::metric($value, $unit);

        return [] === $thresholds ? $detail : \sprintf('%s (%s)', $detail, implode(', ', $thresholds));
    }
}
