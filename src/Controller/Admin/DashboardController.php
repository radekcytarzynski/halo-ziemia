<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\DashboardBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private readonly DashboardBuilder $builder)
    {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'cards' => $this->builder->cards(),
            'cron' => $this->builder->cronStatus(),
        ]);
    }

    /**
     * Fragment HTML do auto-odświeżania (fetch co ~15 s, bez przeładowania strony).
     */
    #[Route('/dashboard/cards', name: 'app_dashboard_cards', methods: ['GET'])]
    public function cards(): Response
    {
        $response = $this->render('admin/_dashboard_cards.html.twig', [
            'cards' => $this->builder->cards(),
            'cron' => $this->builder->cronStatus(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
