<?php

namespace App\Form\Admin;

use App\Entity\VideoFile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Form\Type\VichFileType;

class VideoFileEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', VichFileType::class, [
                'label' => false,
                'required' => false,
                'allow_delete' => true,
                'delete_label' => 'Supprimer ce fichier',
                'download_uri' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '2G',
                        mimeTypes: [
                            'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
                            'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/ogg',
                            'application/pdf',
                            'image/jpeg', 'image/png', 'image/webp',
                        ],
                        mimeTypesMessage: 'Format de fichier non pris en charge.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VideoFile::class,
        ]);
    }
}
