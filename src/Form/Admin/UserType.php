<?php

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

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
        ;

        if (!$isNew) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Ancien mot de passe',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'help' => 'Obligatoire pour définir un nouveau mot de passe.',
            ]);
        }

        $builder->add('plainPassword', PasswordType::class, [
            'label' => $isNew ? 'Mot de passe' : 'Nouveau mot de passe',
            'mapped' => false,
            'required' => $isNew,
            'attr' => ['autocomplete' => 'new-password'],
            'help' => $isNew ? null : 'Laisser vide pour conserver le mot de passe actuel.',
            'constraints' => $isNew
                ? [new Assert\NotBlank(), new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
                // Champ laissé vide = null (conversion par défaut du formulaire), que Length ignore.
                : [new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')],
        ]);

        if (!$isNew) {
            // Le mot de passe enregistré n'a pas encore changé à ce stade
            // (plainPassword n'est pas mappé) : on peut vérifier l'ancien.
            $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $user = $form->getData();
                if (!$user instanceof User || $form->get('plainPassword')->getData() === null) {
                    return;
                }

                $currentPassword = $form->get('currentPassword')->getData();
                if ($currentPassword === null) {
                    $form->get('currentPassword')->addError(new FormError('Saisissez l’ancien mot de passe.'));
                } elseif (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(new FormError('Ancien mot de passe incorrect.'));
                }
            });
        }
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
