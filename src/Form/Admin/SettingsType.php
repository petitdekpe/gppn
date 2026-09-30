<?php

namespace App\Form\Admin;

use App\Enum\CapsuleFormat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données : ['otpEnabled' => bool, 'enabledFormats' => CapsuleFormat[]],
 * lues et enregistrées via App\Service\AppSettings. La couverture par défaut
 * (fichier déposé ou case de suppression) est traitée par le contrôleur.
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
            ->add('defaultCover', FileType::class, [
                'label' => 'Couverture par défaut',
                'required' => false,
                'help' => 'Affichée sur le site public pour les contenus sans image de couverture (hors contenus image, qui montrent leur propre visuel). Ces contenus restent considérés comme sans couverture : ils n’apparaissent pas dans le hero de l’accueil et leur couverture peut toujours être générée.',
                'constraints' => [
                    new Assert\Image(
                        maxSize: '10M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Format d\'image non pris en charge.',
                    ),
                ],
            ])
            ->add('removeDefaultCover', CheckboxType::class, [
                'label' => 'Retirer la couverture par défaut',
                'required' => false,
            ])
        ;
    }
}
