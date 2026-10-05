<?php

namespace App\Form\Admin;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Entity\Thematic;
use App\Repository\CouncilSessionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un sujet est créé depuis la fiche d'un conseil (CouncilSessionController),
 * qui le pré-sélectionne ; le champ permet de corriger un sujet rattaché au
 * mauvais conseil. Les contenus n'ont pas de conseil propre (Video::getCouncilSession()
 * passe par le sujet) : ils suivent le sujet.
 */
class SubjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('councilSession', EntityType::class, [
                'class' => CouncilSession::class,
                'choice_label' => 'title',
                'query_builder' => static fn (CouncilSessionRepository $repository) => $repository->createQueryBuilder('c')->orderBy('c.date', 'DESC'),
                'label' => 'Conseil des ministres',
                'help' => 'Tous les contenus de ce sujet (toutes langues) suivent ce conseil.',
            ])
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
            ->add('learningPoints', TextareaType::class, [
                'label' => 'Ce que vous apprendrez dans ce contenu',
                'help' => 'Une idée par ligne. Laissez vide pour masquer ce bloc sur la page du contenu.',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('keywords', TextType::class, [
                'label' => 'Mots-clés',
                'help' => 'Séparés par des virgules (ex. : acte de naissance, état civil, mairie). Utilisés pour le référencement et la barre de recherche.',
                'required' => false,
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
