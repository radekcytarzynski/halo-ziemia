<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Severity;
use App\Repository\AlertStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bieżący stan alarmowy jednego podmiotu urządzenia:
 * "heartbeat", "rule:{id}" albo "rule:{id}:{klucz}" (reguły z wildcardem – osobny stan per dopasowanie).
 * candidate* realizują anty-flapping (zmiana stanu dopiero po N kolejnych ocenach).
 * notifiedSeverity = stan, o którym ostatnio powiadomiono (null = nic/OK).
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

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 255)]
    private string $title;

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

    /** Ostatnia obserwacja (do wykrywania metryk, które przestały być raportowane). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastObservedAt = null;

    #[ORM\Column(length: 8, nullable: true, enumType: Severity::class)]
    private ?Severity $notifiedSeverity = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

    #[ORM\Column]
    private int $reminderCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Incident $incident = null;

    public function __construct(Device $device, string $subject, string $title, ?AlertRule $rule, \DateTimeImmutable $since)
    {
        $this->device = $device;
        $this->subject = $subject;
        $this->title = mb_substr($title, 0, 255);
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr($title, 0, 255);
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

    public function setLastObservation(?float $value, ?string $message, \DateTimeImmutable $at): void
    {
        $this->lastValue = $value;
        $this->lastMessage = null === $message ? null : mb_substr($message, 0, 500);
        $this->lastObservedAt = $at;
    }

    public function getLastObservedAt(): ?\DateTimeImmutable
    {
        return $this->lastObservedAt;
    }

    public function getNotifiedSeverity(): ?Severity
    {
        return $this->notifiedSeverity;
    }

    public function markNotified(Severity $severity, \DateTimeImmutable $at, bool $reminder): void
    {
        $this->notifiedSeverity = Severity::Ok === $severity ? null : $severity;
        $this->lastNotifiedAt = $at;
        $this->reminderCount = $reminder ? $this->reminderCount + 1 : 0;
    }

    public function getLastNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastNotifiedAt;
    }

    public function getReminderCount(): int
    {
        return $this->reminderCount;
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
