<?php

namespace App\Form\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
            ->add('title', TextType::class, ['label' => 'Titre'])
            // Le slug est calculé automatiquement à partir du titre (voir
            // FormEvents::SUBMIT ci-dessous) plutôt que saisi à la main : un
            // slug mal formé (espaces, ponctuation) servant de nom de dossier
            // sur le serveur faisait planter la mise en ligne. Une fois
            // attribué, il reste stable même si le titre change ensuite,
            // pour ne pas casser l'URL publique d'un contenu déjà
            // partagé/indexé.
            ->add('summary', TextareaType::class, [
                'label' => 'Résumé',
                'attr' => ['rows' => 5],
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
            ->add('language', EntityType::class, [
                'class' => Language::class,
                'choice_label' => 'name',
                'label' => 'Langue',
            ])
            ->add('subject', EntityType::class, [
                'class' => Subject::class,
                'label' => 'Sujet',
                'help' => 'Le sujet porte la thématique et le conseil des ministres. Pas encore de sujet pour ce contenu ? Créez-le d\'abord dans Sujets.',
                'choice_label' => static fn (Subject $subject) => sprintf(
                    '%s — %s (%s)',
                    $subject->getCouncilSession()->getLabel()
                        ?: sprintf('Conseil du %s', $subject->getCouncilSession()->getDate()->format('d/m/Y')),
                    $subject->getReferenceTitle(),
                    $subject->getThematic()->getName(),
                ),
                'query_builder' => static fn (SubjectRepository $repository) => $repository
                    ->createQueryBuilder('s')
                    ->innerJoin('s.councilSession', 'cs')->addSelect('cs')
                    ->innerJoin('s.thematic', 't')->addSelect('t')
                    ->orderBy('cs.date', 'DESC')
                    ->addOrderBy('s.referenceTitle', 'ASC'),
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
            ->add('learningPoints', TextareaType::class, [
                'label' => 'Ce que vous apprendrez dans ce contenu',
                'help' => 'Une idée par ligne. Laissez vide pour masquer ce bloc sur la page du contenu.',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('featured', CheckboxType::class, [
                'label' => 'Mettre en avant (« à la une »)',
                'required' => false,
            ])
            ->add('speaker', EntityType::class, [
                'class' => Speaker::class,
                'choice_label' => 'fullName',
                'label' => 'Intervenant',
                'placeholder' => '— Aucun —',
                'required' => false,
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
