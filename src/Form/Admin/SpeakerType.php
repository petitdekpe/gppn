<?php

namespace App\Form\Admin;

use App\Entity\Government;
use App\Entity\Speaker;
use App\Repository\GovernmentRepository;
use App\Service\SpeakerSigleGuesser;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SpeakerType extends AbstractType
{
    public function __construct(
        private readonly SpeakerSigleGuesser $sigleGuesser,
        private readonly GovernmentRepository $governmentRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, ['label' => 'Nom complet'])
            ->add('sigle', TextType::class, [
                'label' => 'Sigle',
                'required' => false,
                'empty_data' => null,
                'help' => 'Ex : MTCA pour Ministère du Tourisme, de la Culture et des Arts. Deviné automatiquement à partir de la fonction si laissé vide. Pour un ministre conseiller, saisir le sigle seul : le préfixe MCC des noms de fichiers est ajouté automatiquement.',
            ])
            ->add('role', TextType::class, [
                'label' => 'Fonction',
                'required' => false,
                'help' => 'Ex : Directeur de l’ANIP.',
            ])
            ->add('government', EntityType::class, [
                'class' => Government::class,
                'label' => 'Gouvernement',
                'required' => false,
                'placeholder' => 'Hors gouvernement',
                'choice_label' => static fn (Government $government) => $government->getLabel() . ($government->isCurrent() ? ' (actuel)' : ''),
                'choices' => $this->governmentRepository->findOrdered(),
                'help' => 'Après un remaniement, reconduisez plutôt l’intervenant depuis la liste : sa fiche actuelle reste attachée à ses contenus passés.',
            ])
            ->addEventListener(FormEvents::SUBMIT, $this->guessSigle(...))
        ;
    }

    /**
     * Ne devine que si le sigle a été laissé vide : ne doit jamais écraser
     * une valeur saisie ou corrigée à la main (hormis le préfixe MCC retiré).
     */
    private function guessSigle(FormEvent $event): void
    {
        $speaker = $event->getData();
        if (!$speaker instanceof Speaker) {
            return;
        }
        if ($speaker->getSigle() === null) {
            $speaker->setSigle($this->sigleGuesser->guess($speaker->getRole()));
        }
        // « MCCMFAS » saisi pour un ministre conseiller : MCC est ajouté par le code de fichier.
        $speaker->normalizeSigle();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Speaker::class,
        ]);
    }
}
