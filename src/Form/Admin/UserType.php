<?php

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail'])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => User::ASSIGNABLE_ROLES,
                'placeholder' => false,
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $isNew ? 'Mot de passe' : 'Nouveau mot de passe',
                'mapped' => false,
                'required' => $isNew,
                'help' => $isNew ? null : 'Laisser vide pour conserver le mot de passe actuel.',
                'constraints' => $isNew
                    ? [new Assert\NotBlank(), new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
                    : [new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', allowEmptyString: true)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => false,
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
