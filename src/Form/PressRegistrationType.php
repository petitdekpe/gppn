<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Inscription d'un compte presse ou organisation depuis le site : coordonnées
 * (e-mail et téléphone obligatoires) et mot de passe.
 */
class PressRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nom et prénom',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez votre nom.'), new Assert\Length(max: 150)],
            ])
            ->add('organization', TextType::class, [
                'label' => 'Nom de la structure / entité',
                'help' => 'Ex. : Radio Tokpa, ORTB, groupe WhatsApp « Femmes de Djougou ».',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez le nom de votre structure.'), new Assert\Length(max: 150)],
            ])
            ->add('mediaType', ChoiceType::class, [
                'label' => 'Type de structure / entité',
                'choices' => User::MEDIA_TYPES,
                'placeholder' => '— Choisir —',
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un type de structure.')],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez votre adresse e-mail.'), new Assert\Email(message: 'Cette adresse e-mail n’est pas valide.')],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Numéro de téléphone',
                'help' => 'Avec l’indicatif pour un numéro hors du Bénin. Ex. : 01 97 00 00 00.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Indiquez votre numéro de téléphone.'),
                    new Assert\Regex(pattern: '/^\+?[0-9][0-9 .\-()]{6,22}[0-9]$/', message: 'Ce numéro de téléphone n’est pas valide.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Mot de passe', 'help' => '8 caractères au moins.'],
                'second_options' => ['label' => 'Confirmez le mot de passe'],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un mot de passe.'), new Assert\Length(min: 8, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.')],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'J’accepte que mes coordonnées soient conservées pour me transmettre les contenus et me contacter à leur sujet.',
                'mapped' => false,
                'constraints' => [new Assert\IsTrue(message: 'Votre accord est nécessaire pour créer le compte.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
