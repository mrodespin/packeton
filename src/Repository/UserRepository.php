<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Package;
use Packeton\Entity\User;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserRepository extends EntityRepository
{
    public function findUsersMissingApiToken()
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.apiToken IS NULL');
        return $qb->getQuery()->getResult();
    }

    public function findOneByUsernameOrEmail(string $usernameOrEmail)
    {
        if (preg_match('/^.+@\S+\.\S+$/', $usernameOrEmail)) {
            $user = $this->findOneBy(['emailCanonical' => $usernameOrEmail]);
            if (null !== $user) {
                return $user;
            }
        }

        return $this->findOneBy(['usernameCanonical' => $usernameOrEmail]);
    }

    public function getPackageMaintainersQueryBuilder(Package $package, User $excludeUser=null)
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u')
            ->innerJoin('u.packages', 'p', 'WITH', 'p.id = :packageId')
            ->setParameter(':packageId', $package->getId())
            ->orderBy('u.username', 'ASC');

        if ($excludeUser) {
            $qb->andWhere('u.id <> :userId')
                ->setParameter(':userId', $excludeUser->getId());
        }

        return $qb;
    }
}
