<?php

namespace App\Form;

use App\Entity\Suggestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire « Nous contacter » : un message de type contact (voir Suggestion),
 * avec nom et e-mail obligatoires pour pouvoir répondre.
 */
class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nom et prénom',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['placeholder' => 'vous@exemple.bj', 'autocomplete' => 'email'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone (facultatif)',
                'required' => false,
                'attr' => ['autocomplete' => 'tel', 'placeholder' => '+229 01 00 00 00 00'],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Objet',
                'attr' => ['placeholder' => 'Ex. : Demande d’un fichier en haute définition'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre message',
                'attr' => ['rows' => 6],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Suggestion::class,
            'validation_groups' => ['Default', 'contact'],
        ]);
    }
}
