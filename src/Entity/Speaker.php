<?php

namespace App\Entity;

use App\Repository\SpeakerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\String\UnicodeString;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

#[ORM\Entity(repositoryClass: SpeakerRepository::class)]
#[ORM\Table(name: 'speaker')]
#[Uploadable]
class Speaker
{
    /** Préfixe des codes de fichiers des ministres conseillers (voir getFileCode). */
    public const COUNCILLOR_PREFIX = 'MCC';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $fullName = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sigle = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $role = null;

    /** Gouvernement dans lequel l'intervenant a exercé cette fonction (voir Government). */
    #[ORM\ManyToOne(targetEntity: Government::class, inversedBy: 'speakers')]
    private ?Government $government = null;

    /**
     * Portrait officiel (page « Les ministres »), déposé par l'admin ou
     * importé de gouv.bj (app:speakers:import-gouv). Propre à chaque fiche :
     * une reconduction ne le recopie pas, la page prend le plus récent.
     */
    #[UploadableField(mapping: 'speaker_photo', fileNameProperty: 'photoName')]
    private ?File $photoFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $photoUpdatedAt = null;

    /**
     * Rang protocolaire dans son gouvernement (1 = premier), celui de
     * gouv.bj/membres : ordre de la page « Les ministres ». Propre à chaque
     * fiche, il n'est pas repris à la reconduction (le rang change souvent).
     */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $precedence = null;

    /** @var Collection<int, Video> */
    #[ORM\OneToMany(targetEntity: Video::class, mappedBy: 'speaker')]
    private Collection $videos;

    public function __construct()
    {
        $this->videos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getSigle(): ?string
    {
        return $this->sigle;
    }

    public function setSigle(?string $sigle): static
    {
        $this->sigle = $sigle;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getGovernment(): ?Government
    {
        return $this->government;
    }

    public function setGovernment(?Government $government): static
    {
        $this->government = $government;

        return $this;
    }

    public function getPhotoFile(): ?File
    {
        return $this->photoFile;
    }

    /** Touche `photoUpdatedAt` : sans lui, Doctrine ne verrait pas le changement et Vich n'enregistrerait rien. */
    public function setPhotoFile(?File $photoFile = null): static
    {
        $this->photoFile = $photoFile;

        if ($photoFile instanceof File) {
            $this->photoUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPhotoName(): ?string
    {
        return $this->photoName;
    }

    public function setPhotoName(?string $photoName): static
    {
        $this->photoName = $photoName;

        return $this;
    }

    public function getPrecedence(): ?int
    {
        return $this->precedence;
    }

    public function setPrecedence(?int $precedence): static
    {
        $this->precedence = $precedence;

        return $this;
    }

    /**
     * Reconduction dans un autre gouvernement : nouvelle fiche, même nom,
     * même fonction et même sigle, à ajuster si le portefeuille a changé.
     */
    public function reappointIn(Government $government): self
    {
        return (new self())
            ->setFullName($this->fullName)
            ->setSigle($this->sigle)
            ->setRole($this->role)
            ->setGovernment($government);
    }

    /**
     * Clé de comparaison des noms (import, reconduction) : sans accents,
     * casse, ponctuation ni ordre des mots (« TALON Patrice » = « Patrice Talon »).
     */
    public static function nameKey(string $fullName): string
    {
        $ascii = strtolower((new UnicodeString($fullName))->ascii()->toString());
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return implode(' ', $words);
    }

    /**
     * Identifiant de la personne dans les adresses (page intervenant, filtre
     * « Intervenant ») : commun à toutes ses fiches, d'un gouvernement à l'autre.
     */
    public function getPersonSlug(): string
    {
        return self::slugForName($this->fullName);
    }

    public static function slugForName(string $fullName): string
    {
        return str_replace(' ', '-', self::nameKey($fullName));
    }

    /**
     * Un « Ministre Conseiller(ère) » se reconnaît au radical « Conseill »
     * de sa fonction (accord féminin « Conseillère » compris) : aucune
     * donnée structurée dédiée pour l'instant.
     */
    public function isMinistreConseiller(): bool
    {
        return str_contains($this->role ?? '', 'Conseill');
    }

    /**
     * Code de l'intervenant dans les noms de fichiers de l'import en masse
     * (INTERVENANT-LANGUE-FORMAT) : le sigle, précédé de « MCC » pour un
     * ministre conseiller, ce qui distingue les sigles partagés (MFAS / MCCMFAS).
     * Un sigle saisi avec son préfixe (« MCCMFAS ») ne le reçoit pas deux fois.
     */
    public function getFileCode(): ?string
    {
        if (!$this->sigle) {
            return null;
        }

        return $this->isMinistreConseiller()
            ? self::COUNCILLOR_PREFIX . self::withoutCouncillorPrefix($this->sigle)
            : $this->sigle;
    }

    /**
     * Sigle sans le préfixe « MCC » (répété ou non) : c'est getFileCode qui
     * l'ajoute. Un sigle réduit à « MCC » ou presque est laissé tel quel.
     */
    public static function withoutCouncillorPrefix(string $sigle): string
    {
        while (str_starts_with(strtoupper($sigle), self::COUNCILLOR_PREFIX) && strlen($sigle) - strlen(self::COUNCILLOR_PREFIX) >= 2) {
            $sigle = substr($sigle, strlen(self::COUNCILLOR_PREFIX));
        }

        return $sigle;
    }

    /**
     * Sigle à enregistrer : sans préfixe « MCC » pour un ministre conseiller
     * (formulaire, modification en masse, import).
     */
    public function normalizeSigle(): static
    {
        if ($this->sigle !== null && $this->isMinistreConseiller()) {
            $this->sigle = self::withoutCouncillorPrefix($this->sigle);
        }

        return $this;
    }

    /**
     * @return Collection<int, Video>
     */
    public function getVideos(): Collection
    {
        return $this->videos;
    }

    public function __toString(): string
    {
        return $this->fullName;
    }
}
