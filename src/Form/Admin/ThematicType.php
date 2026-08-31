<?php

namespace App\Form\Admin;

use App\Entity\Thematic;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ThematicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('slug', TextType::class, [
                'label' => 'Slug (utilisé dans l’URL)',
                'help' => 'Ex : sante. Laissez inchangé si la thématique est déjà publiée.',
            ])
            ->add('colorHex', ColorType::class, ['label' => 'Couleur'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Thematic::class,
        ]);
    }
}
