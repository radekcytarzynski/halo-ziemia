<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Repository\AlertStateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Szczegóły urządzenia (wersja minimalna; pełny widok w etapie 4).
 */
final class DeviceController extends AbstractController
{
    #[Route('/devices/{id}', name: 'app_device_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Device $device, AlertStateRepository $states): Response
    {
        return $this->render('admin/device/show.html.twig', [
            'device' => $device,
            'states' => $states->findBy(['device' => $device], ['severity' => 'DESC', 'title' => 'ASC']),
        ]);
    }
}
