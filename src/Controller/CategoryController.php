<?php

namespace App\Controller;

use App\Entity\Category;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/category', name: 'api_v2_category_')]
class CategoryController extends AbstractFOSRestController
{
    /**
     * @Rest\View(serializerGroups={"category:collection:read"})
     */
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(ManagerRegistry $doctrine): View
    {
        return $this->view(
            $doctrine->getRepository(Category::class)->findAll()
        );
    }
}
