<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final readonly class LoginSuccessSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof AdminUser) {
            $user->setLastLoginAt($this->clock->now());
            $this->entityManager->flush();
        }
    }
}
