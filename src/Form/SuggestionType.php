<?php

namespace App\Form;

use App\Entity\Suggestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SuggestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'Quel service public voulez-vous voir expliqué ?',
                'attr' => ['placeholder' => 'Ex : Comment renouveler sa carte NPI'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre suggestion',
                'attr' => [
                    'placeholder' => 'Précisez le sujet, la langue souhaitée, ou toute information utile.',
                    'rows' => 5,
                ],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Nom (facultatif)',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (facultatif)',
                'required' => false,
                'attr' => ['placeholder' => 'vous@exemple.bj'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Suggestion::class,
        ]);
    }
}
