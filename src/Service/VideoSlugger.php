<?php

namespace App\Service;

use App\Entity\Video;
use App\Repository\VideoRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Slug d'un contenu, calculé à partir du titre de son sujet plutôt que saisi
 * à la main : un slug mal formé (espaces, ponctuation) servant de nom de
 * dossier sur le serveur faisait planter la mise en ligne. Une fois attribué,
 * il reste stable même si le titre change ensuite, pour ne pas casser l'URL
 * publique d'un contenu déjà partagé/indexé.
 *
 * Utilisé par le formulaire de contenu et par l'import en masse.
 */
class VideoSlugger
{
    public function __construct(private readonly VideoRepository $videoRepository)
    {
    }

    public function assign(Video $video): void
    {
        // Contenu déjà publié avec un slug stable : on n'y touche plus.
        if ($video->getId() !== null && $video->getSlug() !== '') {
            return;
        }

        $base = strtolower((new AsciiSlugger('fr'))->slug($video->getTitle()));

        $slug = $base;
        for ($suffix = 2; $this->isTakenByAnotherVideo($slug, $video); ++$suffix) {
            $slug = $base . '-' . $suffix;
        }

        $video->setSlug($slug);
    }

    private function isTakenByAnotherVideo(string $slug, Video $video): bool
    {
        $existing = $this->videoRepository->findOneBy(['slug' => $slug]);

        return $existing !== null && $existing !== $video;
    }
}
