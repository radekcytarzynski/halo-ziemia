<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RuleOperator;
use App\Enum\RuleSource;
use App\Repository\AlertRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Reguła alarmu per urządzenie.
 *  - source=metric: target = klucz metryki, operator + progi warn/crit,
 *  - source=check:  target = nazwa checka, stan wynika ze statusu checka (ok/warn/fail).
 * consecutiveSamples: ile kolejnych próbek potrzeba do zmiany stanu (anty-flapping).
 * hysteresis: margines, o który wartość musi wrócić za próg, by stan się poprawił.
 */
#[ORM\Entity(repositoryClass: AlertRuleRepository::class)]
#[ORM\Table(name: 'alert_rule')]
#[ORM\Index(name: 'idx_rule_device_target', columns: ['device_id', 'source', 'target'])]
class AlertRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 8, enumType: RuleSource::class)]
    private RuleSource $source;

    #[ORM\Column(length: 190)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 190)]
    private string $target;

    #[ORM\Column(length: 4, enumType: RuleOperator::class)]
    private RuleOperator $operator = RuleOperator::Gt;

    #[ORM\Column(nullable: true)]
    private ?float $warnThreshold = null;

    #[ORM\Column(nullable: true)]
    private ?float $critThreshold = null;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 60)]
    private int $consecutiveSamples = 1;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $hysteresis = 0.0;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $label = null;

    public function __construct(Device $device, RuleSource $source, string $target)
    {
        $this->device = $device;
        $this->source = $source;
        $this->target = $target;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (substr_count($this->target, '*') > 1) {
            $context->buildViolation('Cel może zawierać najwyżej jeden znak „*”.')->atPath('target')->addViolation();
        }
        if (RuleSource::Metric === $this->source && null === $this->warnThreshold && null === $this->critThreshold) {
            $context->buildViolation('Podaj co najmniej jeden próg (WARN lub CRIT).')->atPath('warnThreshold')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getSource(): RuleSource
    {
        return $this->source;
    }

    public function setSource(RuleSource $source): void
    {
        $this->source = $source;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): void
    {
        $this->target = $target;
    }

    public function getOperator(): RuleOperator
    {
        return $this->operator;
    }

    public function setOperator(RuleOperator $operator): void
    {
        $this->operator = $operator;
    }

    public function getWarnThreshold(): ?float
    {
        return $this->warnThreshold;
    }

    public function setWarnThreshold(?float $warnThreshold): void
    {
        $this->warnThreshold = $warnThreshold;
    }

    public function getCritThreshold(): ?float
    {
        return $this->critThreshold;
    }

    public function setCritThreshold(?float $critThreshold): void
    {
        $this->critThreshold = $critThreshold;
    }

    public function getConsecutiveSamples(): int
    {
        return $this->consecutiveSamples;
    }

    public function setConsecutiveSamples(int $consecutiveSamples): void
    {
        $this->consecutiveSamples = $consecutiveSamples;
    }

    public function getHysteresis(): float
    {
        return $this->hysteresis;
    }

    public function setHysteresis(float $hysteresis): void
    {
        $this->hysteresis = $hysteresis;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getDisplayName(): string
    {
        return $this->label ?? $this->target;
    }

    /**
     * Klucz podmiotu stanu alarmowego: "rule:12" albo "rule:12:disk./mnt/backup.used_pct" dla wildcardów.
     */
    public function subjectKey(?string $matchedKey = null): string
    {
        if (null === $this->id) {
            throw new \LogicException('Reguła nie została jeszcze zapisana.');
        }

        return null === $matchedKey || $matchedKey === $this->target
            ? 'rule:'.$this->id
            : 'rule:'.$this->id.':'.$matchedKey;
    }

    /**
     * Tytuł stanu, np. "Zajętość dysku (/mnt/backup)".
     */
    public function titleFor(string $captured): string
    {
        return '' === $captured ? $this->getDisplayName() : \sprintf('%s (%s)', $this->getDisplayName(), $captured);
    }
}
