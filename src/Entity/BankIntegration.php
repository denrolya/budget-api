<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiPlatform\Action\BankIntegrationAccountsAction;
use App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction;
use App\ApiPlatform\Action\BankIntegrationSyncAction;
use App\Bank\BankProvider;
use App\Bank\SyncMethod;
use App\Repository\BankIntegrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Security\Core\User\UserInterface;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: BankIntegrationRepository::class)]
#[ORM\Table(name: 'bank_integration')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['bank_integration:read']],
            openapiContext: [
                'summary' => 'List bank integrations',
                'description' => 'Returns all bank integrations belonging to the authenticated user.',
            ],
        ),
        new Get(
            normalizationContext: ['groups' => ['bank_integration:read']],
            security: 'object.getOwner() == user',
            openapiContext: [
                'summary' => 'Get a bank integration',
                'description' => 'Returns a single bank integration by ID. Access is restricted to the owner.',
            ],
        ),
        new Post(
            denormalizationContext: ['groups' => ['bank_integration:write']],
            normalizationContext: ['groups' => ['bank_integration:read']],
            status: 201,
            openapiContext: [
                'summary' => 'Create a bank integration',
                'description' => 'Creates a new bank integration for the authenticated user. Set `provider` (wise or monobank) and optionally `credentials`. The owner is set automatically from the JWT.',
            ],
        ),
        new Put(
            denormalizationContext: ['groups' => ['bank_integration:write']],
            normalizationContext: ['groups' => ['bank_integration:read']],
            security: 'object.getOwner() == user',
            openapiContext: [
                'summary' => 'Update a bank integration',
                'description' => 'Updates provider, credentials, or active state. Access is restricted to the owner.',
            ],
        ),
        new Delete(
            security: 'object.getOwner() == user',
            openapiContext: [
                'summary' => 'Delete a bank integration',
                'description' => 'Permanently removes the bank integration. Associated BankCardAccounts have their `bankIntegration` FK set to NULL (ON DELETE SET NULL). Access is restricted to the owner.',
            ],
        ),
        new Get(
            uriTemplate: '/bank-integrations/{id}/accounts',
            controller: BankIntegrationAccountsAction::class,
            read: false,
            name: 'bank_integration_accounts',
            openapiContext: [
                'summary' => 'List live accounts from the bank',
                'description' => 'Fetches accounts directly from the bank API for this integration. Returns an array of objects with `externalId`, `name`, `currency`, and `balance`. Use `externalId` values to populate `externalAccountId` on BankCardAccount records.',
            ],
        ),
        new Post(
            uriTemplate: '/bank-integrations/{id}/sync',
            controller: BankIntegrationSyncAction::class,
            read: false,
            write: false,
            name: 'bank_integration_sync',
            openapiContext: [
                'summary' => 'Sync transactions from the bank',
                'description' => 'Polls the bank for new transactions and creates draft Transaction records for each linked BankCardAccount. Accepts optional `?from` and `?to` query parameters (ISO 8601 date strings, e.g. `2024-01-01`) to define the sync window. Returns `{created: N}` with the number of new transactions persisted.',
            ],
        ),
        new Post(
            uriTemplate: '/bank-integrations/{id}/register-webhook',
            controller: BankIntegrationRegisterWebhookAction::class,
            read: false,
            write: false,
            name: 'bank_integration_register_webhook',
            openapiContext: [
                'summary' => 'Register webhook URL with the bank',
                'description' => 'Calls the bank API to register the application webhook URL for this integration. Must be called once after creating a webhook-capable integration (e.g. Monobank or Wise). Returns `{webhookUrl}` that was registered. Returns 422 if the provider does not support webhooks.',
            ],
        ),
    ],
)]
class BankIntegration implements OwnableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    private ?int $id = null;

    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: BankProvider::class)]
    #[Groups(['bank_integration:read', 'bank_integration:write', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Type('string')]
    #[Serializer\Accessor(getter: 'getProviderValue')]
    private BankProvider $provider;

    /**
     * Bank-specific credentials (e.g. ['apiKey' => '...', 'profileId' => '...']).
     * For MVP these are unused (env vars are used). Reserved for future per-user storage.
     */
    #[ORM\Column(type: Types::JSON, nullable: false)]
    #[Groups(['bank_integration:write'])]
    private array $credentials = [];

    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    #[Groups(['bank_integration:read', 'bank_integration:write', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    private bool $isActive = true;

    /**
     * Preferred sync method. Only meaningful when the provider supports both webhook and polling.
        * Null = auto (single-mode providers ignore this).
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: SyncMethod::class)]
    #[Groups(['bank_integration:read', 'bank_integration:write', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Type('string')]
    #[Serializer\Accessor(getter: 'getSyncMethodValue')]
    private ?SyncMethod $syncMethod = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    #[Serializer\Groups(['bank_integration:read', 'account:collection:read', 'account:item:read'])]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    #[Groups(['bank_integration:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?UserInterface
    {
        return $this->owner;
    }

    public function setOwner(UserInterface $user): self
    {
        $this->owner = $user;

        return $this;
    }

    public function getProvider(): BankProvider
    {
        return $this->provider;
    }

    public function getProviderValue(): string
    {
        return $this->provider->value;
    }

    public function setProvider(BankProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function setCredentials(array $credentials): self
    {
        $this->credentials = $credentials;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSyncMethod(): ?SyncMethod
    {
        return $this->syncMethod;
    }

    public function getSyncMethodValue(): ?string
    {
        return $this->syncMethod?->value;
    }

    public function setSyncMethod(?SyncMethod $syncMethod): self
    {
        $this->syncMethod = $syncMethod;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function markSyncedNow(): self
    {
        $this->lastSyncedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Advance lastSyncedAt to the given timestamp if it is later than the current value.
     * Used by webhooks to keep the sync window up-to-date without overwriting a more recent timestamp.
     */
    public function advanceLastSyncedAt(\DateTimeImmutable $timestamp): self
    {
        if ($this->lastSyncedAt === null || $timestamp > $this->lastSyncedAt) {
            $this->lastSyncedAt = $timestamp;
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
