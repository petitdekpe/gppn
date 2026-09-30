<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Actions groupées des listes de l'administration (voir
 * templates/admin/_bulk_bar.html.twig) : lecture de la sélection et compte
 * rendu en message flash, éléments ignorés compris.
 */
trait BulkActionTrait
{
    /**
     * @return list<int>|null identifiants cochés, ou null (message déjà affiché) si rien à faire
     */
    private function bulkIds(Request $request, string $tokenId): ?array
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité expiré : rechargez la page puis recommencez.');

            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $request->request->all('ids')))));
        if ($ids === []) {
            $this->addFlash('error', 'Aucun élément sélectionné.');

            return null;
        }

        return $ids;
    }

    /**
     * @param list<string> $skipped éléments laissés de côté, avec la raison
     */
    private function bulkReport(string $done, array $skipped = []): void
    {
        $this->addFlash('success', $done);
        if ($skipped !== []) {
            $this->addFlash('error', sprintf('%d élément%s ignoré%2$s : %s.', count($skipped), count($skipped) > 1 ? 's' : '', implode(' ; ', $skipped)));
        }
    }

    /**
     * Retour à la liste telle qu'elle était affichée (conseil ouvert, filtres),
     * d'après la page d'origine quand elle vient bien de ce site.
     */
    private function backToList(Request $request, string $route): Response
    {
        $referer = (string) $request->headers->get('referer');

        return str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')
            ? $this->redirect($referer)
            : $this->redirectToRoute($route);
    }

    private function unknownBulkAction(): void
    {
        $this->addFlash('error', 'Action inconnue : rechargez la page puis recommencez.');
    }

    private static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return sprintf('%d %s', $count, $count > 1 ? ($plural ?? $singular . 's') : $singular);
    }
}
