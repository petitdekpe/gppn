<?php

namespace App\Form\Admin;

use App\Entity\Subject;
use App\Entity\Thematic;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le conseil des ministres n'est pas un champ du formulaire : un sujet est
 * toujours créé/modifié depuis la fiche d'un conseil (CouncilSessionController),
 * qui le fixe directement sur l'entité avant d'ouvrir le formulaire.
 */
class SubjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('thematic', EntityType::class, [
                'class' => Thematic::class,
                'choice_label' => 'name',
                'label' => 'Thématique',
            ])
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'help' => 'Partagé par tous les contenus (langues) de ce sujet.',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Résumé',
                'attr' => ['rows' => 5],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Subject::class,
        ]);
    }
}
