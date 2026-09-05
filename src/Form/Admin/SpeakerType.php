<?php

namespace App\Form\Admin;

use App\Entity\Speaker;
use App\Service\SpeakerSigleGuesser;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SpeakerType extends AbstractType
{
    public function __construct(private readonly SpeakerSigleGuesser $sigleGuesser)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, ['label' => 'Nom complet'])
            ->add('sigle', TextType::class, [
                'label' => 'Sigle',
                'required' => false,
                'empty_data' => null,
                'help' => 'Ex : MTCA pour Ministère du Tourisme, de la Culture et des Arts. Deviné automatiquement à partir de la fonction si laissé vide.',
            ])
            ->add('role', TextType::class, [
                'label' => 'Fonction',
                'required' => false,
                'help' => 'Ex : Directeur de l’ANIP.',
            ])
            ->addEventListener(FormEvents::SUBMIT, $this->guessSigle(...))
        ;
    }

    /**
     * Ne complète que si le sigle a été laissé vide : ne doit jamais écraser
     * une valeur saisie ou corrigée à la main.
     */
    private function guessSigle(FormEvent $event): void
    {
        $speaker = $event->getData();
        if (!$speaker instanceof Speaker || $speaker->getSigle() !== null) {
            return;
        }

        $speaker->setSigle($this->sigleGuesser->guess($speaker->getRole()));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Speaker::class,
        ]);
    }
}
