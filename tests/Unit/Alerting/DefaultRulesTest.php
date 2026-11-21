<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerting;

use App\Alerting\DefaultRules;
use App\Entity\Device;
use App\Enum\RuleOperator;
use App\Enum\RuleSource;
use PHPUnit\Framework\TestCase;

final class DefaultRulesTest extends TestCase
{
    public function testTemplatesAreConsistent(): void
    {
        foreach (DefaultRules::templates() as $template) {
            self::assertLessThanOrEqual(1, substr_count($template['target'], '*'), $template['target']);
            self::assertGreaterThanOrEqual(1, $template['consecutive']);

            if (RuleSource::Metric !== $template['source']) {
                continue;
            }
            self::assertTrue(null !== $template['warn'] || null !== $template['crit'], $template['target'].': brak progów');

            if (null !== $template['warn'] && null !== $template['crit']) {
                $ordered = match ($template['operator']) {
                    RuleOperator::Gt, RuleOperator::Gte => $template['crit'] >= $template['warn'],
                    RuleOperator::Lt, RuleOperator::Lte => $template['crit'] <= $template['warn'],
                    default => true,
                };
                self::assertTrue($ordered, $template['target'].': crit powinien być „dalej” niż warn');
            }
        }
    }

    public function testCreatesRulesForDevice(): void
    {
        $device = new Device('NAS', 'nas', new \DateTimeImmutable());
        $rules = DefaultRules::createFor($device);

        self::assertCount(\count(DefaultRules::templates()), $rules);
        self::assertSame($device, $rules[0]->getDevice());
        self::assertSame('Zajętość dysku', $rules[0]->getLabel());
    }
}
