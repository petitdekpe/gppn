<?php

namespace App\Form\Admin;

use App\Enum\CapsuleFormat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données : ['otpEnabled' => bool, 'enabledFormats' => CapsuleFormat[]],
 * lues et enregistrées via App\Service\AppSettings.
 */
class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('otpEnabled', CheckboxType::class, [
                'label' => 'Demander un code reçu par e-mail à la connexion et au changement de mot de passe',
                'required' => false,
                'help' => 'Un code à 6 chiffres, valable 10 minutes, est envoyé à l’adresse e-mail du compte.',
            ])
            ->add('enabledFormats', EnumType::class, [
                'class' => CapsuleFormat::class,
                'label' => 'Types de contenus disponibles sur le site',
                'choice_label' => static fn (CapsuleFormat $format) => $format->getLabel(),
                'multiple' => true,
                'expanded' => true,
                'help' => 'Les fichiers d’un type désactivé restent dans l’administration mais ne sont plus proposés sur le site public. Un contenu sans aucun fichier d’un type activé n’y apparaît plus.',
                'constraints' => [new Assert\Count(min: 1, minMessage: 'Activez au moins un type de contenu.')],
            ])
        ;
    }
}
