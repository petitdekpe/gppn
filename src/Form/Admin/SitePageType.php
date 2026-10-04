<?php

namespace App\Form\Admin;

use App\Entity\SitePage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modification d'une page de texte (admin > Pages). La case « Rétablir le
 * texte par défaut » est traitée par le contrôleur.
 */
class SitePageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'empty_data' => '',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le titre est obligatoire.'),
                    new Assert\Length(max: SitePage::TITLE_MAX_LENGTH),
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'empty_data' => '',
                'attr' => ['rows' => 24, 'class' => 'site-page-editor__input', 'data-markdown-preview-target' => 'input', 'spellcheck' => 'true'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le contenu est obligatoire.'),
                    new Assert\Length(max: SitePage::CONTENT_MAX_LENGTH),
                ],
            ])
            ->add('restoreDefault', CheckboxType::class, [
                'label' => 'Rétablir le texte par défaut (le contenu ci-dessus sera remplacé)',
                'mapped' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SitePage::class,
        ]);
    }
}
