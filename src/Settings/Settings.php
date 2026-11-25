<?php

declare(strict_types=1);

namespace App\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ustawienia globalne (tabela setting) z wartościami domyślnymi i typowanymi getterami.
 */
final class Settings implements ResetInterface
{
    public const string MISSED_INTERVALS_THRESHOLD = 'missed_intervals_threshold';
    public const string REMINDER_INTERVAL_SECONDS = 'reminder_interval_s';
    public const string MAIL_FROM = 'mail_from';
    public const string MAIL_FROM_NAME = 'mail_from_name';
    public const string RETENTION_RAW_DAYS = 'retention_raw_days';
    public const string RETENTION_ROLLUP_DAYS = 'retention_rollup_days';
    public const string LAST_TICK_AT = 'last_tick_at';
    /** Koniec (wyłącznie) ostatniej zagregowanej godziny, epoch UTC. Retencja nie usuwa próbek sprzed rollupu. */
    public const string ROLLUP_UNTIL = 'rollup_until';
    public const string LAST_RETENTION_AT = 'last_retention_at';

    public const int DEFAULT_MISSED_INTERVALS_THRESHOLD = 3;
    public const int DEFAULT_REMINDER_INTERVAL_SECONDS = 21600;
    public const int DEFAULT_RETENTION_RAW_DAYS = 14;
    public const int DEFAULT_RETENTION_ROLLUP_DAYS = 400;

    /** @var array<string, int|float|string|bool|null>|null */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $defaultMailFrom,
    ) {
    }

    public function missedIntervalsThreshold(): int
    {
        return max(1, $this->int(self::MISSED_INTERVALS_THRESHOLD, self::DEFAULT_MISSED_INTERVALS_THRESHOLD));
    }

    /** 0 = przypomnienia wyłączone. */
    public function reminderIntervalSeconds(): int
    {
        return max(0, $this->int(self::REMINDER_INTERVAL_SECONDS, self::DEFAULT_REMINDER_INTERVAL_SECONDS));
    }

    public function mailFrom(): string
    {
        $value = $this->get(self::MAIL_FROM);

        return \is_string($value) && '' !== $value ? $value : $this->defaultMailFrom;
    }

    public function mailFromName(): string
    {
        $value = $this->get(self::MAIL_FROM_NAME);

        return \is_string($value) && '' !== $value ? $value : 'halo-ziemia';
    }

    public function retentionRawDays(): int
    {
        return max(1, $this->int(self::RETENTION_RAW_DAYS, self::DEFAULT_RETENTION_RAW_DAYS));
    }

    public function retentionRollupDays(): int
    {
        return max(1, $this->int(self::RETENTION_ROLLUP_DAYS, self::DEFAULT_RETENTION_ROLLUP_DAYS));
    }

    public function lastTickAt(): ?\DateTimeImmutable
    {
        $value = $this->get(self::LAST_TICK_AT);
        if (!\is_int($value)) {
            return null;
        }

        return new \DateTimeImmutable('@'.$value);
    }

    public function markTick(\DateTimeImmutable $at): void
    {
        $this->set(self::LAST_TICK_AT, $at->getTimestamp());
    }

    public function rollupUntil(): ?int
    {
        $value = $this->get(self::ROLLUP_UNTIL);

        return \is_int($value) ? $value : null;
    }

    public function markRollupUntil(int $hourTs): void
    {
        $this->set(self::ROLLUP_UNTIL, $hourTs);
    }

    public function lastRetentionAt(): ?\DateTimeImmutable
    {
        $value = $this->get(self::LAST_RETENTION_AT);

        return \is_int($value) ? new \DateTimeImmutable('@'.$value) : null;
    }

    public function markRetention(\DateTimeImmutable $at): void
    {
        $this->set(self::LAST_RETENTION_AT, $at->getTimestamp());
    }

    public function get(string $name): int|float|string|bool|null
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Zapis z flush (ustawienia zmieniane rzadko i niezależnie od innych operacji).
     */
    public function set(string $name, int|float|string|bool|null $value): void
    {
        $setting = $this->repository->find($name);
        if (null === $setting) {
            $setting = new Setting($name, $value, $this->clock->now());
            $this->entityManager->persist($setting);
        } else {
            $setting->setValue($value, $this->clock->now());
        }
        $this->entityManager->flush();

        $this->values ??= [];
        $this->values[$name] = $value;
    }

    public function reset(): void
    {
        $this->values = null;
    }

    private function int(string $name, int $default): int
    {
        $value = $this->get($name);

        return \is_int($value) ? $value : $default;
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function all(): array
    {
        if (null === $this->values) {
            $this->values = [];
            foreach ($this->repository->findAll() as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->values;
    }
}
