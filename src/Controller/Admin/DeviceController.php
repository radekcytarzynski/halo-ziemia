<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Form\DeviceType;
use App\Alerting\RuleMatcher;
use App\Device\DeviceManager;
use App\Device\DiskInventory;
use App\Entity\Device;
use App\Enum\RuleSource;
use App\Metrics\ChartQuery;
use App\Metrics\ChartRange;
use App\Metrics\KeyMetrics;
use App\Metrics\MetricUnit;
use App\Repository\AlertRuleRepository;
use App\Repository\AlertStateRepository;
use App\Repository\CheckResultRepository;
use App\Repository\DeviceRepository;
use App\Repository\DeviceTokenRepository;
use App\Repository\EventRepository;
use App\Repository\MetricRepository;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/devices')]
final class DeviceController extends AbstractController
{
    /** Dozwolone czasy trybu serwisowego (minuty). */
    public const array MUTE_DURATIONS = [30 => '30 min', 60 => '1 h', 120 => '2 h', 360 => '6 h', 1440 => '24 h', 10080 => '7 dni'];

    public function __construct(
        private readonly DeviceManager $manager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_device_index', methods: ['GET'])]
    public function index(DeviceRepository $devices): Response
    {
        return $this->render('admin/device/index.html.twig', ['devices' => $devices->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/new', name: 'app_device_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Settings $settings): Response
    {
        $device = new Device('', '', $this->clock->now());
        $form = $this->createForm(DeviceType::class, $device);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->register($device);
            $this->addFlash('success', 'Dodano urządzenie. Utwórz token, aby podłączyć agenta.');

            return $this->redirectToRoute('app_device_show', ['id' => $device->getId(), '_fragment' => 'tokens']);
        }

        return $this->render('admin/device/form.html.twig', [
            'form' => $form,
            'device' => null,
            'global_missed' => $settings->missedIntervalsThreshold(),
        ]);
    }

    #[Route('/{id}', name: 'app_device_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Device $device,
        Request $request,
        AlertStateRepository $states,
        AlertRuleRepository $rules,
        DeviceTokenRepository $tokens,
        CheckResultRepository $checks,
        EventRepository $events,
        Settings $settings,
    ): Response {
        $range = ChartRange::tryFrom($request->query->getString('range')) ?? ChartRange::Day;
        $metrics = $device->getLastSnapshot()['metrics'] ?? [];
        $metricKeys = \is_array($metrics) ? array_values(array_filter(array_keys($metrics), 'is_string')) : [];
        sort($metricKeys);
        $checkNames = array_map(static fn ($c): string => $c->getName(), $checks->findBy(['device' => $device], ['name' => 'ASC']));

        $activeRules = $unusedRules = [];
        foreach ($rules->findBy(['device' => $device], ['id' => 'ASC']) as $rule) {
            $candidates = RuleSource::Metric === $rule->getSource() ? $metricKeys : $checkNames;
            if ([] !== RuleMatcher::matchAll($rule->getTarget(), $candidates)) {
                $activeRules[] = $rule;
            } else {
                $unusedRules[] = $rule;
            }
        }

        $deviceStates = $states->findBy(['device' => $device]);
        usort($deviceStates, static fn ($a, $b): int => [$b->getSeverity()->rank(), $a->getTitle()] <=> [$a->getSeverity()->rank(), $b->getTitle()]);

        $chartLabels = [];
        foreach (KeyMetrics::fromSnapshot($device->getLastSnapshot(), $device->getDiskLabels()) as $m) {
            if ('uptime_s' !== $m['key']) {
                $chartLabels[$m['key']] = $m['label'];
            }
        }
        $chartKeys = array_values(array_unique(array_merge(
            array_keys($chartLabels),
            array_values(array_filter($metricKeys, static fn (string $k): bool => str_starts_with($k, 'mem.available_pct'))),
        )));

        return $this->render('admin/device/show.html.twig', [
            'device' => $device,
            'states' => $deviceStates,
            'active_rules' => $activeRules,
            'unused_rules' => $unusedRules,
            'tokens' => $tokens->findForDevice($device),
            'checks' => $checks->findBy(['device' => $device], ['name' => 'ASC']),
            'events' => $events->findBy(['device' => $device], ['createdAt' => 'DESC', 'id' => 'DESC'], 30),
            'metrics' => \is_array($metrics) ? $metrics : [],
            'metric_keys' => $metricKeys,
            'chart_keys' => $chartKeys,
            'chart_labels' => $chartLabels,
            'disks' => DiskInventory::rows($device),
            'disk_label_max' => DiskInventory::MAX_LABEL_LENGTH,
            'range' => $range,
            'ranges' => ChartRange::cases(),
            'new_token' => $this->consumeNewToken($request),
            'mute_durations' => self::MUTE_DURATIONS,
            'missed_threshold' => $device->getMissedIntervalsThreshold() ?? $settings->missedIntervalsThreshold(),
            'base_url' => $this->getParameter('app.base_url'),
        ]);
    }

    #[Route('/{id}/chart', name: 'app_device_chart', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function chart(Device $device, Request $request, MetricRepository $metrics, ChartQuery $charts): JsonResponse
    {
        $range = ChartRange::tryFrom($request->query->getString('range')) ?? ChartRange::Day;
        $key = $request->query->getString('metric');
        $metric = $metrics->findOneByKey($key);
        $deviceId = $device->getId();

        if (null === $metric || null === $metric->getId() || null === $deviceId) {
            return $this->json(['error' => 'Nieznana metryka.'], 404);
        }

        $now = $this->clock->now();
        $response = $this->json([
            'metric' => $key,
            'unit' => $metric->getUnit() ?? MetricUnit::guess($key),
            'range' => $range->value,
            'bucket' => $range->bucketSeconds(),
            'from' => $now->getTimestamp() - $range->seconds(),
            'to' => $now->getTimestamp(),
            'points' => $charts->series($deviceId, $metric->getId(), $range, $now),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/edit', name: 'app_device_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Device $device, Request $request, Settings $settings): Response
    {
        $form = $this->createForm(DeviceType::class, $device);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->update($device);
            $this->addFlash('success', 'Zapisano zmiany.');

            return $this->redirectToRoute('app_device_show', ['id' => $device->getId()]);
        }

        return $this->render('admin/device/form.html.twig', [
            'form' => $form,
            'device' => $device,
            'global_missed' => $settings->missedIntervalsThreshold(),
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_device_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Device $device, Request $request): Response
    {
        $this->assertCsrf('device-toggle'.$device->getId(), $request);
        $this->manager->setEnabled($device, !$device->isEnabled());
        $this->addFlash('success', $device->isEnabled() ? 'Włączono monitorowanie.' : 'Wyłączono monitorowanie (status PAUSED, bez alarmów).');

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()]);
    }

    #[Route('/{id}/mute', name: 'app_device_mute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mute(Device $device, Request $request): Response
    {
        $this->assertCsrf('device-mute'.$device->getId(), $request);
        $minutes = $request->request->getInt('minutes');
        if (!\array_key_exists($minutes, self::MUTE_DURATIONS)) {
            throw $this->createNotFoundException();
        }

        $this->manager->mute($device, $this->clock->now()->modify(\sprintf('+%d minutes', $minutes)));
        $this->addFlash('success', \sprintf('Tryb serwisowy włączony na %s. Zdarzenia są zapisywane, powiadomienia wstrzymane.', self::MUTE_DURATIONS[$minutes]));

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()]);
    }

    /**
     * Nazwy dysków i dysk pokazywany na karcie dashboardu. Przyjmowane są tylko dyski z listy urządzenia
     * (raportowane przez agenta albo nazwane wcześniej); pusta nazwa usuwa nazwę.
     */
    #[Route('/{id}/disks', name: 'app_device_disks', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disks(Device $device, Request $request): Response
    {
        $this->assertCsrf('device-disks'.$device->getId(), $request);

        // Pary disk[i][id] / disk[i][label] (identyfikatory to ścieżki – nie nadają się na klucze nazw pól).
        $submitted = [];
        foreach ($request->request->all('disk') as $pair) {
            if (\is_array($pair) && \is_string($pair['id'] ?? null) && \is_string($pair['label'] ?? null)) {
                $submitted[$pair['id']] = $pair['label'];
            }
        }
        $labels = [];
        foreach (DiskInventory::rows($device) as $row) {
            // Dysk spoza formularza (np. pojawił się po otwarciu strony) zachowuje dotychczasową nazwę.
            $label = DiskInventory::normalizeLabel($submitted[$row->id] ?? $row->label ?? '');
            if ('' !== $label) {
                $labels[$row->id] = $label;
            }
        }

        $dashboardDisk = $request->request->getString('dashboard_disk');
        if (!\in_array($dashboardDisk, DiskInventory::mounts($device), true)) {
            $dashboardDisk = null;
        }

        $this->manager->updateDisks($device, $labels, $dashboardDisk);
        $this->addFlash('success', 'Zapisano ustawienia dysków. Tytuły alarmów zmienią się przy następnym heartbeacie.');

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId(), '_fragment' => 'disks']);
    }

    #[Route('/{id}/unmute', name: 'app_device_unmute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unmute(Device $device, Request $request): Response
    {
        $this->assertCsrf('device-mute'.$device->getId(), $request);
        $this->manager->unmute($device);
        $this->addFlash('success', 'Zakończono tryb serwisowy.');

        return $this->redirectToRoute('app_device_show', ['id' => $device->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_device_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Device $device, Request $request): Response
    {
        $this->assertCsrf('device-delete'.$device->getId(), $request);
        $name = $device->getName();
        $this->manager->delete($device);
        $this->addFlash('success', \sprintf('Usunięto urządzenie „%s”.', $name));

        return $this->redirectToRoute('app_device_index');
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Nieprawidłowy token CSRF.');
        }
    }

    private function consumeNewToken(Request $request): ?string
    {
        $session = $request->getSession();
        $token = $session->get(DeviceTokenController::SESSION_NEW_TOKEN);
        $session->remove(DeviceTokenController::SESSION_NEW_TOKEN);

        return \is_string($token) ? $token : null;
    }
}
