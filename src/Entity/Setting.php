<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètre du site modifiable depuis l'admin, stocké en clé/valeur : les
 * valeurs typées et leurs défauts sont définis dans App\Service\AppSettings,
 * seule porte d'entrée pour les lire ou les écrire.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'json')]
    private mixed $value;

    public function __construct(string $name, mixed $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}
