<?php

declare(strict_types=1);

namespace App\Token;

use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Enum\EventType;
use App\Repository\DeviceTokenRepository;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class DeviceTokenManager
{
    /** Domyślne okno przejściowe przy rotacji: stary token działa jeszcze przez 24 h. */
    public const int DEFAULT_ROTATION_GRACE_SECONDS = 86400;

    public function __construct(
        private TokenGenerator $generator,
        private DeviceTokenRepository $tokens,
        private EntityManagerInterface $entityManager,
        private EventRecorder $events,
        private ClockInterface $clock,
    ) {
    }

    public function issue(Device $device): IssuedToken
    {
        $now = $this->clock->now();
        $generated = $this->generator->generate();
        $token = new DeviceToken($device, $generated->displayPrefix, $generated->hash, $now);

        $this->entityManager->persist($token);
        $this->events->record($device, EventType::TokenCreated, \sprintf('Utworzono token %s…', $generated->displayPrefix), context: ['prefix' => $generated->displayPrefix]);
        $this->entityManager->flush();

        return new IssuedToken($generated->plainText, $token);
    }

    /**
     * Wydaje nowy token; dotychczasowe aktywne tokeny wygasają po $graceSeconds (0 = natychmiast).
     */
    public function rotate(Device $device, int $graceSeconds = self::DEFAULT_ROTATION_GRACE_SECONDS): IssuedToken
    {
        if ($graceSeconds < 0) {
            throw new \InvalidArgumentException('Okno przejściowe nie może być ujemne.');
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $graceSeconds));

        foreach ($this->tokens->findValidForDevice($device, $now) as $old) {
            if (null === $old->getExpiresAt() || $old->getExpiresAt() > $expiresAt) {
                $old->expireAt($expiresAt);
            }
        }

        $issued = $this->issue($device);
        $this->events->record($device, EventType::TokenRevoked, \sprintf('Rotacja tokenu: poprzednie tokeny ważne do %s UTC', $expiresAt->format('Y-m-d H:i')), context: ['grace_seconds' => $graceSeconds]);
        $this->entityManager->flush();

        return $issued;
    }

    public function revoke(DeviceToken $token): void
    {
        $token->revoke($this->clock->now());
        $this->events->record($token->getDevice(), EventType::TokenRevoked, \sprintf('Unieważniono token %s…', $token->getPrefix()), context: ['prefix' => $token->getPrefix()]);
        $this->entityManager->flush();
    }

    /**
     * Zwraca token tylko, jeśli jest ważny w tej chwili.
     */
    public function findValid(#[\SensitiveParameter] string $plainText): ?DeviceToken
    {
        if (!TokenGenerator::looksValid($plainText)) {
            return null;
        }

        $token = $this->tokens->findOneBy(['tokenHash' => TokenGenerator::hash($plainText)]);
        if (null === $token || !$token->isValidAt($this->clock->now())) {
            return null;
        }

        return $token;
    }
}
