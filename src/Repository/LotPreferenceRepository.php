<?php

namespace App\Repository;

use App\Entity\LotPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LotPreference>
 */
class LotPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LotPreference::class);
    }

    /** @return list<LotPreference> dans l'ordre d'enregistrement */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }

    public function findOneForUser(User $user, int $id): ?LotPreference
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }
}
