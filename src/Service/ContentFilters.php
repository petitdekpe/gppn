<?php

namespace App\Service;

use App\Entity\Language;
use App\Enum\CapsuleFormat;
use App\Repository\CouncilSessionRepository;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Search\SpeakerPeriodFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Filtres des listes de contenus (page Contenus, page d'une langue) : lecture
 * des paramètres, paramètres repris dans la pagination et étiquettes des
 * filtres actifs, chacune avec l'adresse qui retire son filtre.
 */
class ContentFilters
{
    private const ROLES = ['tous', 'ministre', 'conseiller'];

    public function __construct(
        private readonly ThematicRepository $thematicRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly CouncilSessionRepository $councilSessionRepository,
        private readonly MediaAccess $mediaAccess,
        private readonly SpeakerPeriodCriteria $speakerPeriodCriteria,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, string> $routeAttributes paramètres fixes de la route (ex. slug de la langue)
     * @param Language|null         $language        langue imposée par la page : le filtre « Langue » est ignoré
     *
     * @return array{
     *     thematics: list<\App\Entity\Thematic>, languages: list<Language>, formats: list<CapsuleFormat>,
     *     councilSessions: list<\App\Entity\CouncilSession>, query: ?string, page: int, role: string,
     *     speakerPeriod: SpeakerPeriodFilter, matchedPeople: list<array>, querySpeakerIds: list<int>,
     *     selectedThematicSlugs: list<string>, selectedLanguageSlugs: list<string>,
     *     selectedFormatValues: list<string>, selectedCouncilSessionSlugs: list<string>,
     *     routeParams: array<string, mixed>, activeFilters: list<array{label: string, url: string}>, resetUrl: string
     * }
     */
    public function read(Request $request, string $route, array $routeAttributes = [], ?Language $language = null): array
    {
        $queryParams = $request->query->all();
        $list = static fn (string $key): array => isset($queryParams[$key]) ? array_values((array) $queryParams[$key]) : [];
        $thematicSlugs = $list('thematique');
        $languageSlugs = $language === null ? $list('langue') : [];
        $councilSessionSlugs = $list('conseil');

        $thematics = $thematicSlugs ? $this->thematicRepository->findBy(['slug' => $thematicSlugs]) : [];
        $languages = $languageSlugs ? $this->languageRepository->findBy(['slug' => $languageSlugs]) : [];
        $councilSessions = $councilSessionSlugs ? $this->councilSessionRepository->findBy(['slug' => $councilSessionSlugs]) : [];
        // Recherche par format « Audio MP3 » réservée aux médias.
        $formats = $this->mediaAccess->filterFormats(array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $list('format'),
        )));
        $formatValues = array_map(static fn (CapsuleFormat $format) => $format->value, $formats);

        $query = $request->query->getString('q') ?: null;
        $role = $request->query->getString('role') ?: 'tous';
        if (!\in_array($role, self::ROLES, true)) {
            $role = 'tous';
        }

        // Filtres facultatifs, repliés dans la barre.
        $speakerPeriod = $this->speakerPeriodCriteria->fromParams($queryParams);

        // Texte recherché qui désigne un intervenant (nom, sigle) : ses contenus,
        // et le lien vers sa page en tête des résultats.
        $matchedPeople = $query !== null ? $this->speakerPeriodCriteria->searchPeople($query, 3) : [];

        // Filtres retenus (format « Audio MP3 » écarté pour le public), repris dans la pagination.
        $routeParams = array_filter([
            'thematique' => $thematicSlugs,
            'langue' => $languageSlugs,
            'format' => $formatValues,
            'conseil' => $councilSessionSlugs,
            'q' => $query,
            'role' => $role !== 'tous' ? $role : null,
        ]) + $speakerPeriod->queryParams();

        // Étiquettes des filtres actifs, au-dessus des résultats : chacune retire son filtre d'un clic.
        $without = function (string $key, ?string $value = null) use ($routeParams, $route, $routeAttributes): string {
            $params = $routeParams;
            if ($value === null) {
                unset($params[$key]);
            } else {
                $params[$key] = array_values(array_diff((array) ($params[$key] ?? []), [$value]));
            }
            if ($key === 'du') {
                unset($params['au']); // une période se retire d'un bloc
            }

            return $this->urlGenerator->generate($route, $routeAttributes + array_filter($params));
        };
        $activeFilters = [];
        if ($role !== 'tous') {
            $activeFilters[] = ['label' => $role === 'conseiller' ? 'Ministres conseillers' : 'Ministres', 'url' => $without('role')];
        }
        foreach ($thematics as $thematic) {
            $activeFilters[] = ['label' => $thematic->getName(), 'url' => $without('thematique', $thematic->getSlug())];
        }
        foreach ($languages as $selectedLanguage) {
            $activeFilters[] = ['label' => $selectedLanguage->getName(), 'url' => $without('langue', $selectedLanguage->getSlug())];
        }
        foreach ($formats as $format) {
            $activeFilters[] = ['label' => $format->getLabel(), 'url' => $without('format', $format->value)];
        }
        foreach ($councilSessions as $councilSession) {
            $activeFilters[] = ['label' => $councilSession->getTitle(), 'url' => $without('conseil', $councilSession->getSlug())];
        }
        if ($speakerPeriod->hasPerson()) {
            $activeFilters[] = ['label' => $speakerPeriod->personName, 'url' => $without('intervenant')];
        }
        if ($speakerPeriod->hasPeriod()) {
            $activeFilters[] = ['label' => ucfirst($speakerPeriod->periodLabel()), 'url' => $without('du')];
        }
        if ($query !== null) {
            $activeFilters[] = ['label' => '« ' . $query . ' »', 'url' => $without('q')];
        }

        return [
            'thematics' => $thematics,
            'languages' => $language !== null ? [$language] : $languages,
            'formats' => $formats,
            'councilSessions' => $councilSessions,
            'query' => $query,
            'page' => max(1, $request->query->getInt('page', 1)),
            'role' => $role,
            'speakerPeriod' => $speakerPeriod,
            'matchedPeople' => $matchedPeople,
            'querySpeakerIds' => array_merge([], ...array_column($matchedPeople, 'speakerIds')),
            'selectedThematicSlugs' => $thematicSlugs,
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => $formatValues,
            'selectedCouncilSessionSlugs' => $councilSessionSlugs,
            'routeParams' => $routeParams,
            'activeFilters' => $activeFilters,
            'resetUrl' => $this->urlGenerator->generate($route, $routeAttributes),
        ];
    }
}
