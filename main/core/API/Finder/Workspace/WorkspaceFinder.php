<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\API\Finder\Workspace;

use Claroline\AppBundle\API\FinderInterface;
use Claroline\CoreBundle\Entity\User;
use Doctrine\ORM\QueryBuilder;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @DI\Service("claroline.api.finder.workspace")
 * @DI\Tag("claroline.finder")
 */
class WorkspaceFinder implements FinderInterface
{
    /** @var AuthorizationCheckerInterface */
    private $authChecker;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /**
     * WorkspaceFinder constructor.
     *
     * @DI\InjectParams({
     *     "authChecker"  = @DI\Inject("security.authorization_checker"),
     *     "tokenStorage" = @DI\Inject("security.token_storage")
     * })
     *
     * @param AuthorizationCheckerInterface $authChecker
     * @param TokenStorageInterface         $tokenStorage
     */
    public function __construct(
        AuthorizationCheckerInterface $authChecker,
        TokenStorageInterface $tokenStorage
    ) {
        $this->authChecker = $authChecker;
        $this->tokenStorage = $tokenStorage;
    }

    public function getClass()
    {
        return 'Claroline\CoreBundle\Entity\Workspace\Workspace';
    }

    public function configureQueryBuilder(QueryBuilder $qb, array $searches = [], array $sortBy = null)
    {
        if ('cli' !== php_sapi_name() && !$this->authChecker->isGranted('ROLE_ADMIN') && !$this->authChecker->isGranted('ROLE_ANONYMOUS')) {
            /** @var User $currentUser */
            $currentUser = $this->tokenStorage->getToken()->getUser();
            $qb->leftJoin('obj.organizations', 'uo');
            $qb->leftJoin('uo.administrators', 'ua');
            $qb->leftJoin('obj.creator', 'creator');

            $qb->andWhere($qb->expr()->orX(
              $qb->expr()->eq('ua.id', ':userId'),
              $qb->expr()->eq('creator.id', ':userId')
            ));

            $qb->setParameter('userId', $currentUser->getId());
        }

        foreach ($searches as $filterName => $filterValue) {
            //remap some filters...
            if ('meta.personal' === $filterName) {
                $filterName = 'personal';
            }

            if ('meta.model' === $filterName) {
                $filterName = 'model';
            }

            switch ($filterName) {
                case 'createdAfter':
                    $qb->andWhere("obj.created >= :{$filterName}");
                    $qb->setParameter($filterName, $filterValue);
                    break;
                case 'createdBefore':
                    $qb->andWhere("obj.created <= :{$filterName}");
                    $qb->setParameter($filterName, $filterValue);
                    break;
                case 'organization':
                    $qb->leftJoin('obj.organizations', 'o');
                    $qb->andWhere('o.uuid IN (:organizationIds)');
                    $qb->setParameter('organizationIds', is_array($filterValue) ? $filterValue : [$filterValue]);
                    break;
                case 'user':
                    $qb->leftJoin('obj.roles', 'r');
                    $qb->leftJoin('r.users', 'ru');
                    $qb->andWhere($qb->expr()->orX(
                      $qb->expr()->eq('ru.id', ':currentUserId'),
                      $qb->expr()->eq('ru.uuid', ':currentUserId')
                    ));
                    $qb->setParameter('currentUserId', $filterValue);
                    break;
                    //use this whith the 'user' property
              case 'isManager':
                  if ($filterValue) {
                      $qb->andWhere('r.name like :ROLE_WS_MANAGER');
                      $qb->setParameter('ROLE_WS_MANAGER', 'ROLE_WS_MANAGER%');
                  }
                  break;
                default:
                    if (is_string($filterValue)) {
                        $qb->andWhere("UPPER(obj.{$filterName}) LIKE :{$filterName}");
                        $qb->setParameter($filterName, '%'.strtoupper($filterValue).'%');
                    } else {
                        $qb->andWhere("obj.{$filterName} = :{$filterName}");
                        $qb->setParameter($filterName, $filterValue);
                    }
            }
        }

        return $qb;
    }
}
