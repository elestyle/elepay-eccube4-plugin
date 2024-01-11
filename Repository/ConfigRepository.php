<?php

namespace Plugin\Elepay\Repository;

use Eccube\Repository\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\Elepay\Entity\Config;

class ConfigRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    public function get()
    {
        return $this->findOneBy([]);
    }
}
