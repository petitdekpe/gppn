<?php

namespace App\Service;

use App\Entity\Language;
use App\Entity\LotPreference;
use App\Entity\User;
use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\LotPreferenceRepository;

/**
 * Préférences de lot de l'espace média : ce qu'on retient des choix d'un
 * média (langues, formats, intervenant), comment on les décrit et comment
 * on les réapplique. Les sujets et la période ne sont pas retenus : ils
 * changent à chaque conseil des ministres.
 *
 * Un « choix » est un tableau normalisé (identifiants triés, formats
 * connus, intervenant existant) : deux choix identiques se comparent avec ===.
 *
 * @phpstan-type Choices array{languageIds: list<int>, formats: list<string>, speaker: ?string}
 */
class LotPreferences
{
    public const CSRF_TOKEN_ID = 'media_space_preference';

    public function __construct(
        private readonly LotPreferenceRepository $repository,
        private readonly LanguageRepository $languageRepository,
        private readonly SpeakerPeriodCriteria $speakerPeriodCriteria,
    ) {
    }

    /**
     * Choix lus dans les paramètres du constructeur de lot (GET ou POST).
     *
     * @param array<string, mixed> $params
     *
     * @return Choices
     */
    public function choicesFromParams(array $params): array
    {
        $speaker = is_string($params['intervenant'] ?? null) ? $params['intervenant'] : '';

        return $this->normalize(
            array_map('intval', (array) ($params['langue'] ?? [])),
            array_map('strval', (array) ($params['format'] ?? [])),
            $speaker !== '' ? $speaker : null,
        );
    }

    /** @return Choices */
    public function choicesOf(LotPreference $preference): array
    {
        return $this->normalize($preference->getLanguageIds(), $preference->getFormats(), $preference->getSpeaker());
    }

    /** @param Choices $choices */
    public function isEmpty(array $choices): bool
    {
        return $choices['languageIds'] === [] && $choices['formats'] === [] && $choices['speaker'] === null;
    }

    /**
     * Libellés affichés (récapitulatif de la préférence, fenêtre d'enregistrement).
     *
     * @param Choices $choices
     *
     * @return array{languages: string, formats: string, speaker: ?string}
     */
    public function describe(array $choices): array
    {
        $languages = $choices['languageIds'] !== []
            ? $this->languageRepository->findBy(['id' => $choices['languageIds']], ['name' => 'ASC'])
            : [];

        return [
            'languages' => $languages !== [] ? implode(', ', array_map(static fn (Language $l): string => $l->getName(), $languages)) : 'Toutes',
            'formats' => $choices['formats'] !== []
                ? implode(', ', array_map(static fn (string $f): string => CapsuleFormat::from($f)->getLabel(), $choices['formats']))
                : 'Tous',
            'speaker' => $choices['speaker'] !== null ? $this->speakerPeriodCriteria->person($choices['speaker'])['name'] ?? null : null,
        ];
    }

    /**
     * Préférences du média, avec leur description et l'adresse qui les applique.
     *
     * @param Choices $current choix en cours : la préférence identique est signalée
     *
     * @return list<array{preference: LotPreference, summary: array{languages: string, formats: string, speaker: ?string}, params: array<string, mixed>, active: bool}>
     */
    public function rows(User $user, array $current): array
    {
        return array_map(function (LotPreference $preference) use ($current): array {
            $choices = $this->choicesOf($preference);

            return [
                'preference' => $preference,
                'summary' => $this->describe($choices),
                'params' => $this->queryParams($choices),
                'active' => $choices === $current,
            ];
        }, $this->repository->findForUser($user));
    }

    /**
     * Variables du panneau « Mes préférences » (media_space/_preferences.html.twig).
     *
     * @param Choices $current
     *
     * @return array<string, mixed>
     */
    public function panel(User $user, array $current): array
    {
        $rows = $this->rows($user, $current);

        return [
            'preferenceRows' => $rows,
            'currentChoices' => $this->describe($current),
            'currentChoicesSavable' => !$this->isEmpty($current),
            'currentChoicesSaved' => in_array(true, array_column($rows, 'active'), true),
            'preferencePrompt' => $user->hasLotPreferencePrompt(),
            'preferenceMax' => LotPreference::MAX,
            'preferenceTokenId' => self::CSRF_TOKEN_ID,
        ];
    }

    /**
     * Paramètres d'adresse de l'espace média qui reproduisent ces choix.
     *
     * @param Choices $choices
     *
     * @return array<string, mixed>
     */
    public function queryParams(array $choices): array
    {
        return array_filter([
            'langue' => $choices['languageIds'],
            'format' => $choices['formats'],
            'intervenant' => $choices['speaker'],
        ]);
    }

    /**
     * @param list<int>    $languageIds
     * @param list<string> $formats
     *
     * @return Choices
     */
    private function normalize(array $languageIds, array $formats, ?string $speaker): array
    {
        $languageIds = array_values(array_unique(array_filter($languageIds, static fn (int $id): bool => $id > 0)));
        sort($languageIds);

        // Ordre de l'enum (TV, Mobile, Audio…), pas celui des cases cochées.
        $formats = array_values(array_map(
            static fn (CapsuleFormat $f): string => $f->value,
            array_filter(CapsuleFormat::cases(), static fn (CapsuleFormat $f): bool => in_array($f->value, $formats, true)),
        ));

        if ($speaker !== null && $this->speakerPeriodCriteria->person($speaker) === null) {
            $speaker = null;
        }

        return ['languageIds' => $languageIds, 'formats' => $formats, 'speaker' => $speaker];
    }
}
