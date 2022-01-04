<?php

namespace App\Entity;

use App\Traits\OwnableEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity()
 */
class CategoryTag implements OwnableInterface
{
    use OwnableEntity;

    /**
     *
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    protected ?int $id;

    /**
     * @ORM\Column(type="string", length=100)
     */
    #[Groups(['transaction:list', 'account:details', 'debt:list', 'category:list', 'category:tree'])]
    private ?string $name;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Category", mappedBy="tags")
     */
    private array|ArrayCollection $categories;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    #[Pure]
    public function __toString(): string
    {
        return $this->getName() ?: 'New category tag';
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function addCategory(Category $category): CategoryTag
    {
        if(!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): CategoryTag
    {
        if($this->categories->contains($category)) {
            $this->categories->removeElement($category);
        }

        return $this;
    }

    public function getCategories(): ArrayCollection
    {
        return $this->categories;
    }
}
