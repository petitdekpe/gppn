<?php

namespace App\Form\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Thematic;
use App\Entity\Video;
use App\Enum\CapsuleFormat;
use App\Enum\VideoStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Form\Type\VichFileType;

class VideoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Titre'])
            ->add('slug', TextType::class, [
                'label' => 'Slug (utilisé dans l’URL)',
                'help' => 'Ex : obtenir-sa-carte-didentite-biometrique. Laissez inchangé si la vidéo est déjà publiée.',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Résumé',
                'attr' => ['rows' => 5],
            ])
            ->add('format', EnumType::class, [
                'class' => CapsuleFormat::class,
                'choice_label' => static fn (CapsuleFormat $format) => $format->getLabel(),
                'label' => 'Format de la capsule',
            ])
            ->add('status', EnumType::class, [
                'class' => VideoStatus::class,
                'choice_label' => static fn (VideoStatus $status) => $status->getLabel(),
                'label' => 'Statut',
                'help' => 'Pour une capsule Vidéo avec fichier source, le pipeline de traitement met ce statut à jour automatiquement.',
            ])
            ->add('sourceFile', VichFileType::class, [
                'label' => 'Fichier vidéo source',
                'required' => false,
                'allow_delete' => false,
                'help' => 'Déclenche le traitement automatique (hash, vignette, HLS) pour les capsules au format Vidéo.',
                'constraints' => [
                    new Assert\File(
                        maxSize: '2G',
                        mimeTypes: ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'],
                        mimeTypesMessage: 'Merci de déposer un fichier vidéo (MP4, MOV, WebM, MKV).',
                    ),
                ],
            ])
            ->add('thematic', EntityType::class, [
                'class' => Thematic::class,
                'choice_label' => 'name',
                'label' => 'Thématique',
            ])
            ->add('language', EntityType::class, [
                'class' => Language::class,
                'choice_label' => 'name',
                'label' => 'Langue',
            ])
            ->add('durationSeconds', IntegerType::class, [
                'label' => 'Durée (secondes)',
            ])
            ->add('viewsCount', IntegerType::class, [
                'label' => 'Nombre de vues',
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'Date de publication',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('downloadFile', VichFileType::class, [
                'label' => 'Fichier de téléchargement',
                'required' => false,
                'allow_delete' => true,
                'delete_label' => 'Supprimer le fichier actuel',
                'download_uri' => false,
            ])
            ->add('learningPoints', TextareaType::class, [
                'label' => 'Ce que vous apprendrez dans cette vidéo',
                'help' => 'Une idée par ligne. Laissez vide pour masquer ce bloc sur la page de la vidéo.',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('featured', CheckboxType::class, [
                'label' => 'Mettre en avant (« à la une »)',
                'required' => false,
            ])
            ->add('speakers', EntityType::class, [
                'class' => Speaker::class,
                'choice_label' => 'fullName',
                'label' => 'Intervenants',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
