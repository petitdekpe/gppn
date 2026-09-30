<?php

namespace App\Service;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Pagination des listes de l'administration. Le résultat a la forme
 * attendue par templates/partials/_pagination.html.twig.
 */
final class AdminPaginator
{
    /**
     * @return array{items: list<mixed>, total: int, page: int, perPage: int, hasMore: bool}
     */
    public static function paginate(QueryBuilder $queryBuilder, int $page, int $perPage): array
    {
        // Pas de collection jointe dans ces listes (au plus une relation
        // « many-to-one ») : LIMIT direct, sans sous-requête sur les identifiants.
        $paginator = new Paginator($queryBuilder, fetchJoinCollection: false);
        $total = count($paginator);
        // Page au-delà de la fin (éléments supprimés entre-temps) : dernière page.
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));

        $paginator->getQuery()->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return [
            'items' => iterator_to_array($paginator, false),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'hasMore' => $page * $perPage < $total,
        ];
    }
}
