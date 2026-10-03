<?php

namespace App\Entity;

use App\Enum\CapsuleFormat;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[UniqueEntity(fields: ['slug'], message: 'Un contenu avec ce slug existe déjà.')]
#[Uploadable]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 220, unique: true)]
    private string $slug = '';

    #[ORM\Column(enumType: VideoStatus::class, options: ['default' => 'publie'])]
    private VideoStatus $status = VideoStatus::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Language::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Language $language = null;

    /**
     * Le sujet porte la thématique et le conseil des ministres : plusieurs
     * contenus (langues différentes, ou plusieurs fois la même langue)
     * peuvent partager un même sujet.
     */
    #[ORM\ManyToOne(targetEntity: Subject::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subject $subject = null;

    #[ORM\Column]
    private int $durationSeconds = 0;

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column]
    private bool $featured = false;

    /**
     * Image de couverture déposée par l'admin, utilisée dans les zones en
     * object-fit: cover (card, spotlight, fiche détail) plutôt qu'une image
     * extraite du fichier principal.
     */
    #[UploadableField(mapping: 'video_cover', fileNameProperty: 'coverImageName', size: 'coverImageSize', mimeType: 'coverImageMimeType', originalName: 'coverImageOriginalName')]
    private ?File $coverImageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImageName = null;

    #[ORM\Column(nullable: true)]
    private ?int $coverImageSize = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $coverImageMimeType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImageOriginalName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $coverImageUpdatedAt = null;

    /**
     * Couverture tirée de la vidéo TV (VideoCoverGenerator) plutôt que
     * déposée à la main : elle seule peut être remplacée d'office.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $coverGenerated = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Speaker::class, inversedBy: 'videos')]
    private ?Speaker $speaker = null;

    /** @var Collection<int, VideoFile> */
    #[ORM\OneToMany(targetEntity: VideoFile::class, mappedBy: 'video', cascade: ['persist'], orphanRemoval: true)]
    private Collection $files;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getStatus(): VideoStatus
    {
        return $this->status;
    }

    public function setStatus(VideoStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getSubject(): ?Subject
    {
        return $this->subject;
    }

    public function setSubject(?Subject $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Délègue au sujet : conservé pour que le reste du code (templates,
     * requêtes basées sur l'entité) continue de lire `video.thematic` sans
     * savoir que la thématique est en fait portée par le sujet.
     */
    public function getThematic(): ?Thematic
    {
        return $this->subject?->getThematic();
    }

    public function getCouncilSession(): ?CouncilSession
    {
        return $this->subject?->getCouncilSession();
    }

    /**
     * Le titre et le résumé sont désormais saisis une seule fois sur le
     * sujet (partagés par toutes ses langues) plutôt que dupliqués sur
     * chaque contenu.
     */
    public function getTitle(): string
    {
        return $this->subject?->getTitle() ?? '';
    }

    public function getSummary(): string
    {
        return $this->subject?->getSummary() ?? '';
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getDurationLabel(): string
    {
        $minutes = intdiv($this->durationSeconds, 60);
        $seconds = $this->durationSeconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function setViewsCount(int $viewsCount): static
    {
        $this->viewsCount = $viewsCount;

        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getLearningPoints(): ?string
    {
        return $this->subject?->getLearningPoints();
    }

    /**
     * @return string[]
     */
    public function getLearningPointsList(): array
    {
        return $this->subject?->getLearningPointsList() ?? [];
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): static
    {
        $this->featured = $featured;

        return $this;
    }

    public function getCoverImageFile(): ?File
    {
        return $this->coverImageFile;
    }

    /**
     * Ne pas oublier de toucher `coverImageUpdatedAt` : c'est le seul moyen
     * pour Doctrine de détecter qu'une entité déjà persistée a changé quand
     * seul ce champ (non mappé) est modifié, ce dont Vich a besoin pour
     * déclencher le déplacement du nouveau fichier lors d'une mise à jour.
     */
    public function setCoverImageFile(?File $coverImageFile = null): static
    {
        $this->coverImageFile = $coverImageFile;

        if ($coverImageFile instanceof File) {
            $this->coverImageUpdatedAt = new \DateTimeImmutable();
        }
        // Nouvelle image envoyée : déposée à la main, sauf si le générateur
        // rétablit l'indicateur juste après. Vich, lui, réinjecte ici un
        // simple File une fois l'image enregistrée : il ne doit rien changer.
        if ($coverImageFile instanceof UploadedFile) {
            $this->coverGenerated = false;
        }

        return $this;
    }

    public function isCoverGenerated(): bool
    {
        return $this->coverGenerated;
    }

    public function setCoverGenerated(bool $coverGenerated): static
    {
        $this->coverGenerated = $coverGenerated;

        return $this;
    }

    public function getCoverImageName(): ?string
    {
        return $this->coverImageName;
    }

    public function setCoverImageName(?string $coverImageName): static
    {
        $this->coverImageName = $coverImageName;

        return $this;
    }

    public function getCoverImageSize(): ?int
    {
        return $this->coverImageSize;
    }

    public function setCoverImageSize(?int $coverImageSize): static
    {
        $this->coverImageSize = $coverImageSize;

        return $this;
    }

    public function getCoverImageMimeType(): ?string
    {
        return $this->coverImageMimeType;
    }

    public function setCoverImageMimeType(?string $coverImageMimeType): static
    {
        $this->coverImageMimeType = $coverImageMimeType;

        return $this;
    }

    public function getCoverImageOriginalName(): ?string
    {
        return $this->coverImageOriginalName;
    }

    public function setCoverImageOriginalName(?string $coverImageOriginalName): static
    {
        $this->coverImageOriginalName = $coverImageOriginalName;

        return $this;
    }

    public function getCoverImageUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->coverImageUpdatedAt;
    }

    public function setCoverImageUpdatedAt(?\DateTimeImmutable $coverImageUpdatedAt): static
    {
        $this->coverImageUpdatedAt = $coverImageUpdatedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSpeaker(): ?Speaker
    {
        return $this->speaker;
    }

    public function setSpeaker(?Speaker $speaker): static
    {
        $this->speaker = $speaker;

        return $this;
    }

    /**
     * Toujours triée par type (voir les valeurs préfixées de VideoFileType),
     * que la collection vienne d'être chargée depuis la base ou d'avoir reçu
     * de nouvelles entrées en mémoire (ordre d'insertion non garanti dans ce
     * second cas).
     *
     * @return Collection<int, VideoFile>
     */
    public function getFiles(): Collection
    {
        return $this->files->matching(Criteria::create()->orderBy(['type' => Criteria::ASC]));
    }

    public function addFile(VideoFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setVideo($this);
        }

        return $this;
    }

    public function removeFile(VideoFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    public function getVideoFileByType(VideoFileType $type): ?VideoFile
    {
        foreach ($this->files as $file) {
            if ($file->getType() === $type) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Formats de diffusion réellement déposés, pour la liste de l'admin :
     * TV (1080p, à défaut la version allégée 480p), Mobile (vertical), Audio.
     * Un emplacement vide (sans fichier) compte comme absent.
     *
     * @return array{tv: ?VideoFile, mobile: ?VideoFile, audio: ?VideoFile}
     */
    public function getDistributionFiles(): array
    {
        $uploaded = function (VideoFileType ...$types): ?VideoFile {
            foreach ($types as $type) {
                $file = $this->getVideoFileByType($type);
                if ($file?->getFileName() !== null) {
                    return $file;
                }
            }

            return null;
        };

        return [
            'tv' => $uploaded(VideoFileType::MP4_1080P, VideoFileType::MP4_480P),
            'mobile' => $uploaded(VideoFileType::MP4_VERTICAL),
            'audio' => $uploaded(VideoFileType::AUDIO),
        ];
    }

    /**
     * Fichier utilisé par le fil vertical (bouton flottant « Feed ») : un
     * contenu sans version 9:16 déposée n'y apparaît simplement pas, cf.
     * VideoRepository::findVerticalFeed().
     */
    public function getVerticalFile(): ?VideoFile
    {
        return $this->getVideoFileByType(VideoFileType::MP4_VERTICAL);
    }

    /**
     * Fichier à utiliser pour le lecteur/aperçu : le premier disponible dans
     * l'ordre de VideoFileType::cases() (1080p, 480p, vertical, audio, pdf,
     * image) — un contenu n'a plus de format unique déclaré, ce sont les
     * fichiers réellement déposés qui déterminent ce qui peut être affiché.
     */
    public function getPrimaryPlaybackFile(): ?VideoFile
    {
        foreach (VideoFileType::cases() as $type) {
            $file = $this->getVideoFileByType($type);
            if ($file !== null && $file->getFileName() !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Formats (catégories) réellement disponibles parmi les fichiers déposés,
     * dédupliqués — un contenu ayant à la fois un MP4 1080p et 480p ne doit
     * apparaître qu'une fois dans la catégorie « Vidéo » (voir la carte du
     * catalogue public, qui affiche ces catégories en badges).
     *
     * @return CapsuleFormat[]
     */
    public function getAvailableFormats(): array
    {
        $formats = [];
        foreach ($this->files as $file) {
            if ($file->getFileName() === null) {
                continue;
            }
            $category = $file->getType()->getCategory();
            $formats[$category->value] = $category;
        }

        return array_values($formats);
    }
}
