<?php

namespace App\Exception;

/**
 * Couverture impossible à tirer de la vidéo. Le message s'adresse à
 * l'éditeur : il est affiché tel quel dans l'administration.
 */
final class CoverGenerationException extends \RuntimeException
{
}
