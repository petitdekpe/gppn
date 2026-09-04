<?php

namespace App\Form\Admin;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Entity\Thematic;
use App\Repository\CouncilSessionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('councilSession', EntityType::class, [
                'class' => CouncilSession::class,
                'label' => 'Conseil des ministres',
                'choice_label' => static fn (CouncilSession $councilSession) => $councilSession->getLabel()
                    ? sprintf('%s (%s)', $councilSession->getLabel(), $councilSession->getDate()->format('d/m/Y'))
                    : sprintf('Conseil des ministres du %s', $councilSession->getDate()->format('d/m/Y')),
                'query_builder' => static fn (CouncilSessionRepository $repository) => $repository
                    ->createQueryBuilder('cs')
                    ->orderBy('cs.date', 'DESC'),
            ])
            ->add('thematic', EntityType::class, [
                'class' => Thematic::class,
                'choice_label' => 'name',
                'label' => 'Thématique',
            ])
            ->add('referenceTitle', TextType::class, [
                'label' => 'Titre du sujet',
                'help' => 'Repère interne pour l\'administration. Chaque contenu ajouté sous ce sujet garde son propre titre, dans sa langue.',
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
