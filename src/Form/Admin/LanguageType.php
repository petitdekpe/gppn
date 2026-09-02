<?php

namespace App\Form\Admin;

use App\Entity\Language;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;

class LanguageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            // Sur FormEvents::SUBMIT (donc avant que les contraintes de validation
            // — dont l'unicité du slug — ne s'exécutent) plutôt que dans le
            // contrôleur après coup, sans quoi un doublon de slug remonte en
            // erreur SQL brute au lieu d'un message de validation propre.
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
                $language = $event->getData();
                if ($language instanceof Language) {
                    $slugger = new AsciiSlugger('fr');
                    $language->setSlug(strtolower($slugger->slug($language->getName())));
                }
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Language::class,
        ]);
    }
}
