<?php

namespace App\Form\Admin;

use App\Entity\Government;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class GovernmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'help' => 'Ex : « Gouvernement du 23 mai 2021 » ou « Remaniement du 2 janvier 2025 ».',
                'constraints' => [new Assert\NotBlank(message: 'Donnez un libellé au gouvernement.'), new Assert\Length(max: 150)],
            ])
            ->add('startedAt', DateType::class, [
                'label' => 'Entrée en fonction',
                'required' => false,
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'help' => 'Permet à l’import en masse de proposer les intervenants en place à la date du conseil des ministres.',
            ])
            ->add('current', CheckboxType::class, [
                'label' => 'Gouvernement actuel',
                'required' => false,
                'help' => 'Proposé par défaut pour les nouveaux intervenants et les imports. Un seul gouvernement peut l’être.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Government::class,
        ]);
    }
}
