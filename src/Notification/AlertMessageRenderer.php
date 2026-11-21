<?php

declare(strict_types=1);

namespace App\Notification;

use App\Alerting\AlertItem;
use App\Entity\Device;
use App\Enum\Severity;
use App\Metrics\KeyMetrics;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Treść powiadomień (tekst + prosty HTML) po polsku.
 */
final readonly class AlertMessageRenderer
{
    public const string SUBJECT_PREFIX = '[halo-ziemia]';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<AlertItem> $problems
     * @param list<AlertItem> $recoveries
     */
    public function renderChange(Device $device, array $problems, array $recoveries, \DateTimeImmutable $now): RenderedMessage
    {
        if ([] !== $problems) {
            $worst = Severity::worst(...array_map(static fn (AlertItem $i): Severity => $i->to, $problems));
            $headline = $worst->label();
            $single = 1 === \count($problems) && [] === $recoveries ? $problems[0] : null;
        } else {
            $headline = 'ODZYSKANO';
            $single = 1 === \count($recoveries) ? $recoveries[0] : null;
        }

        return $this->render($device, $this->subject($headline, $device, $single), $problems, $recoveries, false, $now);
    }

    /**
     * @param list<AlertItem> $items
     */
    public function renderReminder(Device $device, array $items, \DateTimeImmutable $now): RenderedMessage
    {
        $worst = Severity::worst(...array_map(static fn (AlertItem $i): Severity => $i->to, $items));
        $single = 1 === \count($items) ? $items[0] : null;

        return $this->render($device, $this->subject('PRZYPOMNIENIE '.$worst->label(), $device, $single), $items, [], true, $now);
    }

    public function renderTest(\DateTimeImmutable $now): RenderedMessage
    {
        $context = ['now' => $now, 'panel_url' => $this->urlGenerator->generate('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL)];

        return new RenderedMessage(
            self::SUBJECT_PREFIX.' Test powiadomień',
            $this->twig->render('email/test.txt.twig', $context),
            $this->twig->render('email/test.html.twig', $context),
        );
    }

    /**
     * @param list<AlertItem> $problems
     * @param list<AlertItem> $recoveries
     */
    private function render(Device $device, string $subject, array $problems, array $recoveries, bool $reminder, \DateTimeImmutable $now): RenderedMessage
    {
        $context = [
            'device' => $device,
            'problems' => $problems,
            'recoveries' => $recoveries,
            'reminder' => $reminder,
            'now' => $now,
            'key_metrics' => KeyMetrics::fromSnapshot($device->getLastSnapshot()),
            'device_url' => $this->urlGenerator->generate('app_device_show', ['id' => $device->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return new RenderedMessage(
            $subject,
            $this->twig->render('email/alert.txt.twig', $context),
            $this->twig->render('email/alert.html.twig', $context),
        );
    }

    private function subject(string $headline, Device $device, ?AlertItem $single): string
    {
        $subject = \sprintf('%s %s: %s', self::SUBJECT_PREFIX, $headline, $device->getName());

        return null === $single ? $subject : $subject.' – '.$single->title;
    }
}
