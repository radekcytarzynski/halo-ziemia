<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Device\DeviceManager;
use App\Entity\Device;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Device>
 */
final class DeviceType extends AbstractType
{
    public function __construct(private readonly DeviceManager $devices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Pusty identyfikator → generowany z nazwy (przed walidacją).
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $device = $event->getData();
            if ($device instanceof Device && '' === $device->getSlug() && '' !== $device->getName()) {
                $device->setSlug($this->devices->suggestSlug($device->getName()));
            }
        });

        $builder
            ->add('name', TextType::class, ['label' => 'Nazwa', 'empty_data' => '', 'attr' => ['maxlength' => 100, 'autofocus' => true]])
            ->add('slug', TextType::class, [
                'label' => 'Identyfikator',
                'empty_data' => '',
                'required' => false,
                'help' => 'Małe litery, cyfry, myślnik. Puste = z nazwy.',
                'attr' => ['maxlength' => 100, 'pattern' => '[a-z0-9][a-z0-9\-]*'],
            ])
            ->add('description', TextareaType::class, ['label' => 'Opis', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('location', TextType::class, ['label' => 'Lokalizacja', 'required' => false, 'help' => 'Na przyszłość: grupowanie urządzeń.'])
            ->add('enabled', CheckboxType::class, ['label' => 'Monitorowanie włączone', 'required' => false])
            ->add('expectedIntervalSeconds', IntegerType::class, [
                'label' => 'Interwał heartbeatu (s)',
                'empty_data' => (string) Device::DEFAULT_INTERVAL_SECONDS,
                'attr' => ['min' => 10, 'max' => 86400, 'data-hb' => 'interval'],
            ])
            ->add('missedIntervalsThreshold', IntegerType::class, [
                'label' => 'Brakujące heartbeaty do alarmu',
                'required' => false,
                'help' => 'Puste = wartość globalna z ustawień.',
                'attr' => ['min' => 1, 'max' => 1000, 'data-hb' => 'missed'],
            ])
            ->add('graceSeconds', IntegerType::class, [
                'label' => 'Dodatkowy margines (s)',
                'empty_data' => (string) Device::DEFAULT_GRACE_SECONDS,
                'attr' => ['min' => 0, 'max' => 86400, 'data-hb' => 'grace'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Device::class]);
    }
}
