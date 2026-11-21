<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('missed_intervals_threshold', IntegerType::class, [
                'label' => 'Brakujące heartbeaty do alarmu DOWN',
                'help' => 'Domyślnie dla wszystkich urządzeń (można nadpisać per urządzenie).',
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 1000)],
            ])
            ->add('reminder_interval_hours', NumberType::class, [
                'label' => 'Przypomnienia o trwającym problemie co (h)',
                'help' => '0 = bez przypomnień.',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.5', 'min' => 0],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 720)],
            ])
            ->add('mail_from', EmailType::class, [
                'label' => 'Nadawca maili',
                'required' => false,
                'help' => 'Puste = MAILER_FROM z konfiguracji serwera.',
                'constraints' => [new Assert\Email()],
            ])
            ->add('mail_from_name', TextType::class, ['label' => 'Nazwa nadawcy', 'required' => false, 'constraints' => [new Assert\Length(max: 100)]])
            ->add('retention_raw_days', IntegerType::class, [
                'label' => 'Retencja próbek surowych (dni)',
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 90)],
            ])
            ->add('retention_rollup_days', IntegerType::class, [
                'label' => 'Retencja rollupów godzinnych i zdarzeń (dni)',
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 30, max: 3650)],
            ]);
    }
}
