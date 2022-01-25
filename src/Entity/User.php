<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use JMS\Serializer\Annotation\Groups;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity(repositoryClass=UserRepository::class)
 */
#[ApiResource(
    collectionOperations: [],
    itemOperations: ['get', 'put'],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    #[Groups(['user:item:read'])]
    private ?int $id;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     */
    #[Groups(['user:item:read'])]
    private ?string $username;

    /**
     * @ORM\Column(type="json")
     */
    #[Groups(['user:item:read'])]
    private ?array $roles = [];

    /**
     * @var string The hashed password
     * @ORM\Column(type="string")
     */
    private string $password;

    /**
     * @ORM\Column(name="base_currency", type="string", length=3, nullable=false)
     */
    #[Groups(['user:item:read', 'user:write'])]
    private string $baseCurrency = 'EUR';

    /**
     * @ORM\Column(name="dashboard_statistics", type="json")
     */
    #[Groups(['user:item:read', 'user:write'])]
    private array $dashboardStatistics;

    #[Pure]
    public function __construct()
    {
        $this->dashboardStatistics = [
            'moneyFlow',
            'expenseCategoriesTree',
            'shortExpenseForGivenPeriod',
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @deprecated since Symfony 5.3, use getUserIdentifier instead
     */
    public function getUsername(): string
    {
        return (string)$this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Returning a salt is only needed, if you are not using a modern
     * hashing algorithm (e.g. bcrypt or sodium) in your security.yaml.
     *
     * @see UserInterface
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function setBaseCurrency(string $baseCurrency): self
    {
        $this->baseCurrency = $baseCurrency;

        return $this;
    }

    public function getDashboardStatistics(): array
    {
        return $this->dashboardStatistics;
    }

    public function setDashboardStatistics(array $dashboardStatistics): self
    {
        $this->dashboardStatistics = $dashboardStatistics;

        return $this;
    }
}
