<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\Groups;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ApiResource(
    description: 'The authenticated user. Exposes user preferences such as base currency and dashboard widget configuration.',
    operations: [
        new Get(description: 'Get the current user profile and preferences.'),
        new Put(description: 'Update user preferences (base currency, dashboard statistics).'),
    ],
)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['user:item:read'])]
    #[ApiProperty(identifier: false)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    #[Groups(['user:item:read'])]
    #[ApiProperty(identifier: true)]
    private ?string $username;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['user:item:read'])]
    private ?array $roles = [];

    #[ORM\Column(type: Types::STRING)]
    private string $password;

    #[ApiProperty(description: 'The currency used for all converted values and statistics across the app.')]
    #[ORM\Column(name: 'base_currency', type: Types::STRING, length: 3, nullable: false)]
    #[Groups(['user:item:read', 'user:write'])]
    private string $baseCurrency = 'EUR';

    #[ApiProperty(description: 'Ordered list of dashboard widget identifiers to display on the home page (e.g. moneyFlow, expenseCategoriesTree).')]
    #[ORM\Column(name: 'dashboard_statistics', type: Types::JSON)]
    #[Groups(['user:item:read', 'user:write'])]
    private array $dashboardStatistics;

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

    public function getUsername(): string
    {
        return (string) $this->username;
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
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles ?? [];
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(?array $roles): self
    {
        $this->roles = $roles ?? [];

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
