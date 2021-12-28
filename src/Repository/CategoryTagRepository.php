<?php

namespace App\Repository;

use App\Entity\CategoryTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CategoryTag|null find($id, $lockMode = null, $lockVersion = null)
 * @method CategoryTag|null findOneBy(array $criteria, array $orderBy = null)
 * @method CategoryTag[]    findAll()
 * @method CategoryTag[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryTagRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryTag::class);
    }
}
