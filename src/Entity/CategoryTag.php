<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Traits\OwnableEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity()
 */
#[ApiResource(
    collectionOperations: [],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
)]
class CategoryTag implements OwnableInterface
{
    use OwnableEntity;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    protected ?int $id;

    /**
     * @ORM\Column(type="string", length=100)
     */
    #[Groups(['transaction:list', 'account:details', 'debt:list', 'category:create', 'category:list', 'category:tree'])]
    private ?string $name;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Category", mappedBy="tags")
     */
    private null|array|ArrayCollection|PersistentCollection $categories;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    #[Pure]
    public function __toString(): string
    {
        return $this->getName() ?: 'New category tag';
    }

    public function getId(): ?int
    {
        return $this->id;
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
