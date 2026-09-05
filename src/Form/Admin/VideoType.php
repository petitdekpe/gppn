<?php

namespace App\Form\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\SpeakerRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;

class VideoType extends AbstractType
{
    public function __construct(private readonly VideoRepository $videoRepository)
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
                    '%s — %s (%s)',
                    $subject->getCouncilSession()->getLabel()
                        ?: sprintf('Conseil du %s', $subject->getCouncilSession()->getDate()->format('d/m/Y')),
                    $subject->getTitle(),
                    $subject->getThematic()->getName(),
                ),
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
            ->add('coverPositionX', HiddenType::class)
            ->add('coverPositionY', HiddenType::class)
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
                // Même distinction que côté public (VideoRepository::findVideoIdsBySpeakerRole) :
                // un « Ministre Conseiller(ère) » se reconnaît au radical « Conseill »
                // (accord féminin « Conseillère » sinon non détecté par un match strict).
                'group_by' => static fn (Speaker $speaker) => str_contains($speaker->getRole() ?? '', 'Conseill')
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
        if (!$video instanceof Video) {
            return;
        }

        // Contenu déjà publié avec un slug stable : on n'y touche plus.
        if ($video->getId() !== null && $video->getSlug() !== '') {
            return;
        }

        $slugger = new AsciiSlugger('fr');
        $base = strtolower($slugger->slug($video->getTitle()));

        $slug = $base;
        for ($suffix = 2; $this->slugTakenByAnotherVideo($slug, $video); ++$suffix) {
            $slug = $base . '-' . $suffix;
        }

        $video->setSlug($slug);
    }

    private function slugTakenByAnotherVideo(string $slug, Video $video): bool
    {
        $existing = $this->videoRepository->findOneBy(['slug' => $slug]);

        return $existing !== null && $existing !== $video;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
