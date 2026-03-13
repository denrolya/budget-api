<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint', columns: ['endpoint'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The push service endpoint URL provided by the browser. */
    #[ORM\Column(length: 2048)]
    private string $endpoint;

    /** ECDH public key (p256dh), base64url-encoded. */
    #[ORM\Column(length: 512)]
    private string $p256dh;

    /** Auth secret, base64url-encoded. */
    #[ORM\Column(length: 256)]
    private string $auth;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $p256dh, string $auth)
    {
        $this->user      = $user;
        $this->endpoint  = $endpoint;
        $this->p256dh    = $p256dh;
        $this->auth      = $auth;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getP256dh(): string { return $this->p256dh; }
    public function getAuth(): string { return $this->auth; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
