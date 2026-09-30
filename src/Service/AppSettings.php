<?php

namespace App\Service;

use App\Entity\Setting;
use App\Enum\CapsuleFormat;
use App\Enum\VideoFileType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paramètres du site réglables depuis l'admin (page « Paramètres »). Un
 * paramètre jamais enregistré prend sa valeur par défaut : OTP désactivé,
 * tous les types de contenus activés, aucune couverture par défaut.
 */
class AppSettings
{
    private const OTP_ENABLED = 'otp_enabled';
    private const ENABLED_FORMATS = 'enabled_formats';
    private const DEFAULT_COVER = 'default_cover';

    /** @var array<string, mixed>|null chargé une seule fois par requête */
    private ?array $values = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Code à usage unique envoyé par e-mail à la connexion et avant tout
     * changement de mot de passe.
     */
    public function isOtpEnabled(): bool
    {
        return (bool) $this->get(self::OTP_ENABLED, false);
    }

    public function setOtpEnabled(bool $enabled): void
    {
        $this->set(self::OTP_ENABLED, $enabled);
    }

    /**
     * Types de contenus proposés sur le site public, dans l'ordre de
     * CapsuleFormat::cases().
     *
     * @return CapsuleFormat[]
     */
    public function getEnabledFormats(): array
    {
        $values = $this->get(self::ENABLED_FORMATS, null);
        if (!\is_array($values)) {
            return CapsuleFormat::cases();
        }

        return array_values(array_filter(
            CapsuleFormat::cases(),
            static fn (CapsuleFormat $format) => \in_array($format->value, $values, true),
        ));
    }

    /**
     * @param CapsuleFormat[] $formats
     */
    public function setEnabledFormats(array $formats): void
    {
        $this->set(self::ENABLED_FORMATS, array_values(array_map(static fn (CapsuleFormat $format) => $format->value, $formats)));
    }

    /**
     * Nom, dans video_cover.storage, de l'image affichée à la place de la
     * couverture des contenus qui n'en ont pas (voir VideoCoverUrlResolver).
     */
    public function getDefaultCover(): ?string
    {
        $value = $this->get(self::DEFAULT_COVER, null);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function setDefaultCover(?string $fileName): void
    {
        $this->set(self::DEFAULT_COVER, $fileName);
    }

    /**
     * Types de fichiers à masquer sur le site public (voir CapsuleFormatFilter).
     *
     * @return VideoFileType[]
     */
    public function getDisabledFileTypes(): array
    {
        $enabled = $this->getEnabledFormats();
        $disabled = [];
        foreach (CapsuleFormat::cases() as $format) {
            if (!\in_array($format, $enabled, true)) {
                array_push($disabled, ...$format->getVideoFileTypes());
            }
        }

        return $disabled;
    }

    private function get(string $name, mixed $default): mixed
    {
        if ($this->values === null) {
            $this->values = [];
            foreach ($this->entityManager->getRepository(Setting::class)->findAll() as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return \array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    private function set(string $name, mixed $value): void
    {
        $setting = $this->entityManager->find(Setting::class, $name);
        if ($setting === null) {
            $this->entityManager->persist(new Setting($name, $value));
        } else {
            $setting->setValue($value);
        }
        $this->entityManager->flush();

        $this->values = null;
    }
}
