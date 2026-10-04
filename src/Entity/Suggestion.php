<?php

namespace App\Entity;

use App\Repository\SuggestionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message reçu depuis le site : suggestion de sujet (« Proposer un sujet »,
 * nom et e-mail facultatifs) ou demande de contact (« Nous contacter »,
 * nom et e-mail obligatoires pour pouvoir répondre : groupe de validation
 * « contact »). Les deux se traitent dans l'admin, menu « Messages ».
 */
#[ORM\Entity(repositoryClass: SuggestionRepository::class)]
#[ORM\Table(name: 'suggestion')]
class Suggestion
{
    public const KIND_SUGGESTION = 'suggestion';
    public const KIND_CONTACT = 'contact';

    /** @var array<string, string> */
    public const KIND_LABELS = [
        self::KIND_SUGGESTION => 'Suggestion',
        self::KIND_CONTACT => 'Contact',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Merci d’indiquer un sujet.')]
    #[Assert\Length(max: 200)]
    private string $subject = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Merci de décrire votre suggestion.')]
    #[Assert\Length(max: 3000)]
    private string $message = '';

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\NotBlank(message: 'Merci d’indiquer votre nom.', groups: ['contact'])]
    #[Assert\Length(max: 150)]
    private ?string $fullName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\NotBlank(message: 'Merci d’indiquer votre adresse e-mail pour recevoir notre réponse.', groups: ['contact'])]
    #[Assert\Email(message: 'Cette adresse email n’est pas valide.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]*$/', message: 'Ce numéro de téléphone n’est pas valide.')]
    private ?string $phone = null;

    #[ORM\Column(length: 20, options: ['default' => self::KIND_SUGGESTION])]
    private string $kind = self::KIND_SUGGESTION;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $treated = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isTreated(): bool
    {
        return $this->treated;
    }

    public function setTreated(bool $treated): static
    {
        $this->treated = $treated;

        return $this;
    }
}
