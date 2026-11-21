<?php

declare(strict_types=1);

namespace App\Alerting;

use App\Entity\AlertRule;
use App\Enum\EventType;
use App\Repository\AlertStateRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Zmiany reguł z panelu. Zmiana progów nie resetuje stanu (kolejny heartbeat oceni go na nowo),
 * natomiast zmiana celu, wyłączenie lub usunięcie reguły czyści jej stany i zamyka incydenty.
 */
final readonly class RuleManager
{
    public function __construct(
        private AlertStateRepository $states,
        private EntityManagerInterface $entityManager,
        private EventRecorder $events,
        private ClockInterface $clock,
    ) {
    }

    public function create(AlertRule $rule): void
    {
        $this->entityManager->persist($rule);
        $this->events->record($rule->getDevice(), EventType::ConfigChange, \sprintf('Dodano regułę „%s”', $rule->getDisplayName()));
        $this->entityManager->flush();
    }

    /**
     * @param array{source: string, target: string, enabled: bool} $before stan sprzed edycji
     */
    public function update(AlertRule $rule, array $before): void
    {
        $structural = $before['source'] !== $rule->getSource()->value || $before['target'] !== $rule->getTarget();
        if ($structural || ($before['enabled'] && !$rule->isEnabled())) {
            $this->resetStates($rule);
        }

        $this->events->record($rule->getDevice(), EventType::ConfigChange, \sprintf('Zmieniono regułę „%s”', $rule->getDisplayName()), context: [
            'warn' => $rule->getWarnThreshold(),
            'crit' => $rule->getCritThreshold(),
            'enabled' => $rule->isEnabled(),
        ]);
        $this->entityManager->flush();
    }

    public function delete(AlertRule $rule): void
    {
        $this->resetStates($rule);
        $this->events->record($rule->getDevice(), EventType::ConfigChange, \sprintf('Usunięto regułę „%s”', $rule->getDisplayName()));
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }

    private function resetStates(AlertRule $rule): void
    {
        $now = $this->clock->now();
        foreach ($this->states->findBy(['rule' => $rule]) as $state) {
            $state->getIncident()?->close($now);
            $this->entityManager->remove($state);
        }
    }
}
