<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Severity;
use App\Repository\AlertStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bieżący stan alarmowy jednego podmiotu urządzenia: "heartbeat" albo "rule:{id}".
 * candidate* realizują anty-flapping (zmiana stanu dopiero po N kolejnych ocenach).
 */
#[ORM\Entity(repositoryClass: AlertStateRepository::class)]
#[ORM\Table(name: 'alert_state')]
#[ORM\UniqueConstraint(name: 'uniq_alert_state_subject', columns: ['device_id', 'subject'])]
class AlertState
{
    public const string SUBJECT_HEARTBEAT = 'heartbeat';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 64)]
    private string $subject;

    /** null dla podmiotu "heartbeat". */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AlertRule $rule;

    #[ORM\Column(length: 8, enumType: Severity::class)]
    private Severity $severity = Severity::Ok;

    #[ORM\Column(length: 8, nullable: true, enumType: Severity::class)]
    private ?Severity $candidateSeverity = null;

    #[ORM\Column]
    private int $candidateCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $since;

    #[ORM\Column(nullable: true)]
    private ?float $lastValue = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

    #[ORM\Column]
    private int $reminderCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Incident $incident = null;

    public function __construct(Device $device, string $subject, ?AlertRule $rule, \DateTimeImmutable $since)
    {
        $this->device = $device;
        $this->subject = $subject;
        $this->rule = $rule;
        $this->since = $since;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getRule(): ?AlertRule
    {
        return $this->rule;
    }

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function setSeverity(Severity $severity, \DateTimeImmutable $since): void
    {
        $this->severity = $severity;
        $this->since = $since;
    }

    public function getCandidateSeverity(): ?Severity
    {
        return $this->candidateSeverity;
    }

    public function getCandidateCount(): int
    {
        return $this->candidateCount;
    }

    public function setCandidate(?Severity $candidateSeverity, int $candidateCount): void
    {
        $this->candidateSeverity = $candidateSeverity;
        $this->candidateCount = $candidateCount;
    }

    public function getSince(): \DateTimeImmutable
    {
        return $this->since;
    }

    public function getLastValue(): ?float
    {
        return $this->lastValue;
    }

    public function getLastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function setLastObservation(?float $value, ?string $message): void
    {
        $this->lastValue = $value;
        $this->lastMessage = $message;
    }

    public function getLastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    public function setLastNotifiedAt(?\DateTimeImmutable $lastNotifiedAt): void
    {
        $this->lastNotifiedAt = $lastNotifiedAt;
    }

    public function getReminderCount(): int
    {
        return $this->reminderCount;
    }

    public function setReminderCount(int $reminderCount): void
    {
        $this->reminderCount = $reminderCount;
    }

    public function getIncident(): ?Incident
    {
        return $this->incident;
    }

    public function setIncident(?Incident $incident): void
    {
        $this->incident = $incident;
    }
}
