<?php

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class OtpCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'Code reçu par e-mail',
            'attr' => [
                'inputmode' => 'numeric',
                'autocomplete' => 'one-time-code',
                'maxlength' => 6,
                'pattern' => '[0-9]{6}',
                'autofocus' => true,
                'class' => 'otp-input',
            ],
            'constraints' => [
                new Assert\NotBlank(message: 'Saisissez le code reçu par e-mail.'),
                new Assert\Regex(pattern: '/^\s*\d{6}\s*$/', message: 'Le code comporte 6 chiffres.'),
            ],
        ]);
    }
}
