<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\DashboardBuilder;
use App\Admin\Form\SettingsType;
use App\Enum\EventType;
use App\Monitoring\Watchdog;
use App\Settings\Settings;
use App\Timeline\EventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        Settings $settings,
        DashboardBuilder $dashboard,
        Watchdog $watchdog,
        EventRecorder $events,
        EntityManagerInterface $entityManager,
    ): Response {
        $storedFrom = $settings->get(Settings::MAIL_FROM);
        $storedName = $settings->get(Settings::MAIL_FROM_NAME);
        $form = $this->createForm(SettingsType::class, [
            'missed_intervals_threshold' => $settings->missedIntervalsThreshold(),
            'reminder_interval_hours' => $settings->reminderIntervalSeconds() / 3600,
            'mail_from' => \is_string($storedFrom) ? $storedFrom : null,
            'mail_from_name' => \is_string($storedName) ? $storedName : null,
            'retention_raw_days' => $settings->retentionRawDays(),
            'retention_rollup_days' => $settings->retentionRollupDays(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{missed_intervals_threshold: int, reminder_interval_hours: float|int, mail_from: string|null, mail_from_name: string|null, retention_raw_days: int, retention_rollup_days: int} $data */
            $data = $form->getData();
            $settings->set(Settings::MISSED_INTERVALS_THRESHOLD, $data['missed_intervals_threshold']);
            $settings->set(Settings::REMINDER_INTERVAL_SECONDS, (int) round($data['reminder_interval_hours'] * 3600));
            $settings->set(Settings::MAIL_FROM, $data['mail_from'] ?? '');
            $settings->set(Settings::MAIL_FROM_NAME, $data['mail_from_name'] ?? '');
            $settings->set(Settings::RETENTION_RAW_DAYS, $data['retention_raw_days']);
            $settings->set(Settings::RETENTION_ROLLUP_DAYS, $data['retention_rollup_days']);
            $events->record(null, EventType::ConfigChange, 'Zmieniono ustawienia globalne');
            $entityManager->flush();
            $this->addFlash('success', 'Zapisano ustawienia.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            'cron' => $dashboard->cronStatus(),
            'watchdog_configured' => $watchdog->isConfigured(),
            'mail_from_effective' => $settings->mailFrom(),
            'project_dir' => $this->getParameter('kernel.project_dir'),
            'php_cli' => $this->getParameter('app.php_cli'),
        ]);
    }
}
