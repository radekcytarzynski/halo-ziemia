<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Entity\AlertRule;
use App\Enum\RuleOperator;
use App\Enum\RuleSource;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AlertRule>
 */
final class AlertRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Nazwa', 'required' => false, 'attr' => ['maxlength' => 190]])
            ->add('source', EnumType::class, [
                'class' => RuleSource::class,
                'label' => 'Typ',
                'choice_label' => static fn (RuleSource $s): string => RuleSource::Metric === $s ? 'Metryka (progi)' : 'Check agenta (status)',
            ])
            ->add('target', TextType::class, [
                'label' => 'Cel',
                'empty_data' => '',
                'help' => 'Klucz metryki lub nazwa checka. Jeden „*” dopasowuje wiele, np. disk.*.used_pct.',
                'attr' => ['list' => 'metric-keys', 'maxlength' => 190, 'class' => 'mono'],
            ])
            ->add('warnThreshold', NumberType::class, ['label' => 'Próg WARN', 'required' => false, 'scale' => 4, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('critThreshold', NumberType::class, ['label' => 'Próg CRIT', 'required' => false, 'scale' => 4, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('enabled', CheckboxType::class, ['label' => 'Reguła włączona', 'required' => false])
            ->add('operator', EnumType::class, [
                'class' => RuleOperator::class,
                'label' => 'Alarm, gdy wartość',
                'choice_label' => static fn (RuleOperator $o): string => $o->symbol().' próg',
            ])
            ->add('consecutiveSamples', IntegerType::class, [
                'label' => 'Kolejne próbki do zmiany stanu',
                'empty_data' => '1',
                'help' => 'Anty-flapping: ile pomiarów z rzędu musi potwierdzić zmianę.',
                'attr' => ['min' => 1, 'max' => 60],
            ])
            ->add('hysteresis', NumberType::class, [
                'label' => 'Histereza',
                'empty_data' => '0',
                'scale' => 4,
                'html5' => true,
                'help' => 'O ile wartość musi wrócić za próg, by alarm ustał.',
                'attr' => ['step' => 'any', 'min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AlertRule::class]);
    }
}
