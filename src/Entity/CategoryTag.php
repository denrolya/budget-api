<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use App\DTO\TagInput;
use App\DTO\TagOutput;
use App\Traits\OwnableEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use JMS\Serializer\Annotation as Serializer;

/**
 * @ORM\Entity()
 * @UniqueEntity("name")
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
    shortName: 'tags',
    denormalizationContext: ['groups' => 'tag:write'],
    input: TagInput::class,
    normalizationContext: ['groups' => 'tags:read'],
    output: TagOutput::class,
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
     * @Assert\NotBlank()
     * @ORM\Column(type="string", length=100)
     */
    #[Groups(['account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read'])]
    private ?string $name;

    /**
     * @ORM\ManyToMany(targetEntity=Category::class, mappedBy="tags")
     */
    private Collection $categories;

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
