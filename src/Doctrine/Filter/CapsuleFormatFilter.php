<?php

namespace App\Doctrine\Filter;

use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Masque les fichiers des types de contenus désactivés dans les Paramètres.
 * Activé uniquement sur le site public (voir CapsuleFormatFilterSubscriber) :
 * Doctrine l'applique à toute lecture de video_file, y compris le chargement
 * de Video::$files et les sous-requêtes EXISTS, si bien que le lecteur, les
 * badges, les téléchargements, le feed et les filtres de recherche ignorent
 * ces fichiers sans traitement particulier. L'admin continue de tout voir.
 */
final class CapsuleFormatFilter extends SQLFilter
{
    public const NAME = 'capsule_format';

    /** @var VideoFileType[] */
    private array $disabledTypes = [];

    /**
     * @param VideoFileType[] $types
     */
    public function setDisabledTypes(array $types): void
    {
        $this->disabledTypes = $types;
    }

    /**
     * Condition DQL « le contenu a au moins un fichier visible », à ajouter
     * aux requêtes publiques quand le filtre est actif : sans elle, un
     * contenu dont tous les fichiers sont d'un type désactivé resterait
     * listé (et compté) sans rien à lire ni télécharger.
     *
     * @return string|null null quand le filtre est inactif (rien à ajouter)
     */
    public static function visibleVideoCondition(EntityManagerInterface $entityManager, string $videoAlias): ?string
    {
        if (!$entityManager->getFilters()->isEnabled(self::NAME)) {
            return null;
        }

        return sprintf('EXISTS (SELECT 1 FROM %s vf_%2$s WHERE vf_%2$s.video = %2$s AND vf_%2$s.fileName IS NOT NULL)', VideoFile::class, $videoAlias);
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== VideoFile::class || $this->disabledTypes === []) {
            return '';
        }

        // Valeurs issues de l'enum, jamais d'une saisie : on peut les quoter directement.
        $connection = $this->getConnection();
        $values = implode(', ', array_map(static fn (VideoFileType $type) => $connection->quote($type->value), $this->disabledTypes));

        return sprintf('%s.type NOT IN (%s)', $targetTableAlias, $values);
    }
}
