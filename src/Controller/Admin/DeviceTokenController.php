<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\DeviceToken;
use App\Token\DeviceTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tokeny agentów. Jawny token jest przekazywany do widoku jednorazowo przez sesję
 * (usuwany przy pierwszym odczycie) – w bazie zostaje tylko hash.
 */
final class DeviceTokenController extends AbstractController
{
    public const string SESSION_NEW_TOKEN = 'hz_new_token';

    /** Dozwolone okna przejściowe przy rotacji (sekundy). */
    public const array ROTATION_GRACE = [0 => 'natychmiast', 3600 => '1 h', 86400 => '24 h', 604800 => '7 dni'];

    public function __construct(private readonly DeviceTokenManager $tokens)
    {
    }

    #[Route('/devices/{id}/tokens', name: 'app_token_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(Device $device, Request $request): Response
    {
        $this->assertCsrf('token'.$device->getId(), $request);
        $issued = $this->tokens->issue($device);
        $request->getSession()->set(self::SESSION_NEW_TOKEN, $issued->plainText);

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId(), '_fragment' => 'tokens']);
    }

    #[Route('/devices/{id}/tokens/rotate', name: 'app_token_rotate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rotate(Device $device, Request $request): Response
    {
        $this->assertCsrf('token'.$device->getId(), $request);
        $grace = $request->request->getInt('grace', 86400);
        if (!\array_key_exists($grace, self::ROTATION_GRACE)) {
            throw $this->createNotFoundException();
        }

        $issued = $this->tokens->rotate($device, $grace);
        $request->getSession()->set(self::SESSION_NEW_TOKEN, $issued->plainText);
        $this->addFlash('success', \sprintf('Wydano nowy token. Poprzednie działają jeszcze: %s.', self::ROTATION_GRACE[$grace]));

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId(), '_fragment' => 'tokens']);
    }

    #[Route('/tokens/{id}/revoke', name: 'app_token_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(DeviceToken $token, Request $request): Response
    {
        $this->assertCsrf('token'.$token->getDevice()->getId(), $request);
        $this->tokens->revoke($token);
        $this->addFlash('success', \sprintf('Unieważniono token %s…', $token->getPrefix()));

        return $this->redirectToRoute('app_device_show', ['id' => $token->getDevice()->getId(), '_fragment' => 'tokens']);
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Nieprawidłowy token CSRF.');
        }
    }
}
