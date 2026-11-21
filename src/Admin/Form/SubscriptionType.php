<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Entity\Device;
use App\Entity\Subscription;
use App\Enum\Severity;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Subscription>
 */
final class SubscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('device', EntityType::class, [
                'class' => Device::class,
                'label' => 'Urządzenie',
                'required' => false,
                'placeholder' => 'Wszystkie urządzenia',
                'choice_label' => 'name',
            ])
            ->add('minSeverity', EnumType::class, [
                'class' => Severity::class,
                'label' => 'Od poziomu',
                'choices' => [Severity::Warn, Severity::Crit],
                'choice_label' => static fn (Severity $s): string => Severity::Warn === $s ? 'WARN (ostrzeżenia i wyżej)' : 'CRIT (tylko krytyczne i DOWN)',
            ])
            ->add('notifyRecovery', CheckboxType::class, ['label' => 'Wysyłaj „odzyskano”', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Subscription::class]);
    }
}
