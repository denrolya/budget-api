<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\IncomeCategoryRepository")
 */
class IncomeCategory extends Category
{
    public function getType(): string
    {
        return Category::INCOME_CATEGORY_TYPE;
    }
}
