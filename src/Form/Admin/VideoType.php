<?php

namespace App\Form\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\SpeakerRepository;
use App\Repository\SubjectRepository;
use App\Service\VideoSlugger;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Form\Type\VichImageType;

class VideoType extends AbstractType
{
    public function __construct(private readonly VideoSlugger $videoSlugger)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Le sujet en premier : il porte la thématique, le conseil des
            // ministres, le titre et le résumé (partagés par toutes ses
            // langues), donc tout le reste du formulaire en dépend. Le slug
            // est calculé automatiquement à partir du titre du sujet (voir
            // FormEvents::SUBMIT ci-dessous) plutôt que saisi à la main : un
            // slug mal formé (espaces, ponctuation) servant de nom de dossier
            // sur le serveur faisait planter la mise en ligne. Une fois
            // attribué, il reste stable même si le titre change ensuite, pour
            // ne pas casser l'URL publique d'un contenu déjà partagé/indexé.
            ->add('subject', EntityType::class, [
                'class' => Subject::class,
                'label' => 'Sujet',
                'help' => 'Le sujet porte la thématique, le conseil des ministres, le titre et le résumé. Pas encore de sujet pour ce contenu ? Créez-le d\'abord depuis la fiche du conseil des ministres concerné.',
                'choice_label' => static fn (Subject $subject) => sprintf(
                    '%s (%s)',
                    $subject->getTitle(),
                    $subject->getThematic()->getName(),
                ),
                // Un groupe par conseil ; le calendrier latéral (council-calendar)
                // n'affiche que les sujets du conseil choisi grâce à data-council-id.
                'group_by' => static fn (Subject $subject) => $subject->getCouncilSession()->getLabel()
                    ?: sprintf('Conseil du %s', $subject->getCouncilSession()->getDate()->format('d/m/Y')),
                'choice_attr' => static fn (Subject $subject) => [
                    'data-council-id' => $subject->getCouncilSession()->getId(),
                ],
                'query_builder' => static fn (SubjectRepository $repository) => $repository
                    ->createQueryBuilder('s')
                    ->innerJoin('s.councilSession', 'cs')->addSelect('cs')
                    ->innerJoin('s.thematic', 't')->addSelect('t')
                    ->orderBy('cs.date', 'DESC')
                    ->addOrderBy('s.title', 'ASC'),
            ])
            ->add('language', EntityType::class, [
                'class' => Language::class,
                'choice_label' => 'name',
                'label' => 'Langue',
            ])
            ->add('status', EnumType::class, [
                'class' => VideoStatus::class,
                'choice_label' => static fn (VideoStatus $status) => $status->getLabel(),
                'label' => 'Statut',
                'help' => 'Masqué retire le contenu du site public sans le supprimer.',
            ])
            ->add('durationSeconds', IntegerType::class, [
                'label' => 'Durée (secondes)',
            ])
            ->add('coverImageFile', VichImageType::class, [
                'label' => 'Image de couverture',
                'help' => 'Affichée dans les cartes, à la une et la fiche détail. Sans image déposée, elle est tirée automatiquement de la vidéo HD 1080p, à 15 s.',
                'required' => false,
                'allow_delete' => true,
                'delete_label' => 'Supprimer cette image',
                'download_uri' => false,
                'constraints' => [
                    new Assert\Image(
                        maxSize: '10M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Format d\'image non pris en charge.',
                    ),
                ],
            ])
            ->add('files', CollectionType::class, [
                'label' => false,
                'entry_type' => VideoFileEntryType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
            ])
            ->add('featured', CheckboxType::class, [
                'label' => 'Mettre en avant (« à la une »)',
                'required' => false,
            ])
            ->add('speaker', EntityType::class, [
                'class' => Speaker::class,
                'label' => 'Intervenant',
                'placeholder' => '— Aucun —',
                'required' => false,
                'choice_label' => static fn (Speaker $speaker) => $speaker->getSigle()
                    ? sprintf('%s — %s', $speaker->getSigle(), $speaker->getFullName())
                    : $speaker->getFullName(),
                // Même distinction que côté public (VideoRepository::findVideoIdsBySpeakerRole).
                'group_by' => static fn (Speaker $speaker) => $speaker->isMinistreConseiller()
                    ? 'Ministres conseillers'
                    : 'Ministres',
                'query_builder' => static fn (SpeakerRepository $repository) => $repository
                    ->createQueryBuilder('sp')
                    ->orderBy('sp.fullName', 'ASC'),
            ])
            ->addEventListener(FormEvents::SUBMIT, $this->assignSlug(...))
        ;
    }

    private function assignSlug(FormEvent $event): void
    {
        $video = $event->getData();
        if ($video instanceof Video) {
            $this->videoSlugger->assign($video);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
