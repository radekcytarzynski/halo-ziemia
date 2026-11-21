<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Entity\Recipient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;

/**
 * Kanał na razie tylko e-mail; przy kolejnych kanałach dojdzie pole wyboru kanału.
 *
 * @extends AbstractType<Recipient>
 */
final class RecipientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Imię / nazwa', 'empty_data' => '', 'attr' => ['maxlength' => 100]])
            ->add('address', EmailType::class, ['label' => 'Adres e-mail', 'empty_data' => '', 'constraints' => [new Email(mode: Email::VALIDATION_MODE_HTML5)]])
            ->add('enabled', CheckboxType::class, ['label' => 'Aktywny', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recipient::class]);
    }
}
