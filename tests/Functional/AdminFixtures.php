<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait AdminFixtures
{
    public const string ADMIN_EMAIL = 'admin@example.test';
    public const string ADMIN_PASSWORD = 'correct horse battery staple';

    protected function createAdmin(): AdminUser
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new AdminUser(self::ADMIN_EMAIL, new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, self::ADMIN_PASSWORD));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
