<?php

namespace App\Form\Admin;

use App\Entity\CouncilSession;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CouncilSessionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'Date du conseil des ministres',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé (optionnel)',
                'required' => false,
                'help' => 'Ex : Conseil extraordinaire. Laissez vide pour afficher simplement la date.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CouncilSession::class,
        ]);
    }
}
