<?php

namespace App\Entity;

use App\Repository\SubjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubjectRepository::class)]
#[ORM\Table(name: 'subject')]
class Subject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CouncilSession::class, inversedBy: 'subjects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CouncilSession $councilSession = null;

    #[ORM\ManyToOne(targetEntity: Thematic::class, inversedBy: 'subjects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Thematic $thematic = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $summary = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $learningPoints = null;

    /**
     * Mots-clés séparés par des virgules : alimentent la balise meta keywords
     * de la page du contenu et la barre de recherche.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $keywords = null;

    /** @var Collection<int, Video> */
    #[ORM\OneToMany(targetEntity: Video::class, mappedBy: 'subject')]
    private Collection $videos;

    public function __construct()
    {
        $this->videos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCouncilSession(): ?CouncilSession
    {
        return $this->councilSession;
    }

    public function setCouncilSession(?CouncilSession $councilSession): static
    {
        $this->councilSession = $councilSession;

        return $this;
    }

    public function getThematic(): ?Thematic
    {
        return $this->thematic;
    }

    public function setThematic(?Thematic $thematic): static
    {
        $this->thematic = $thematic;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getLearningPoints(): ?string
    {
        return $this->learningPoints;
    }

    public function setLearningPoints(?string $learningPoints): static
    {
        $this->learningPoints = $learningPoints;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getLearningPointsList(): array
    {
        if ($this->learningPoints === null || trim($this->learningPoints) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $this->learningPoints)), static fn (string $line) => $line !== ''));
    }

    public function getKeywords(): ?string
    {
        return $this->keywords;
    }

    /**
     * Normalise la saisie (virgules, points-virgules ou retours à la ligne)
     * en une liste « mot, mot, mot » sans doublon.
     */
    public function setKeywords(?string $keywords): static
    {
        $list = [];
        foreach (preg_split('/[,;\n]+/', (string) $keywords) as $keyword) {
            $keyword = trim(preg_replace('/\s+/', ' ', $keyword));
            if ($keyword !== '') {
                $list[mb_strtolower($keyword)] ??= $keyword;
            }
        }

        $this->keywords = $list !== [] ? implode(', ', $list) : null;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getKeywordsList(): array
    {
        return $this->keywords !== null ? explode(', ', $this->keywords) : [];
    }

    /**
     * @return Collection<int, Video>
     */
    public function getVideos(): Collection
    {
        return $this->videos;
    }
}
