<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatIdempotencyKeyRepository;

/**
 * 1.36 (REQ-RUNTIME-003 rule 7): an `Idempotency-Key` a user sent with a write (forward, thread reply, send), the hash
 * of that request and what it created, written in the same unit of work as the write: a replay of the same key and
 * request gets the same answer without a second effect; another request under the same key is refused. Purged after
 * a day.
 */
#[ORM\Entity(repositoryClass: ChatIdempotencyKeyRepository::class)]
#[ORM\Table(name: 'nowo_chat_idempotency')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_idempotency', columns: ['user_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_nowo_chat_idempotency_at', columns: ['created_at'])]
class ChatIdempotencyKey
{
    /** Longest key accepted (characters). */
    public const int MAX_KEY_LENGTH = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string>|null Uuids of the messages the request created */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /** `user` as a reference (`doctrine.user_reference: id` / `string` maps this instead of the relation). */
    private ?string $userRef;

    /**
     * Claims a key for a request.
     *
     * @param ChatUserInterface $user Who sent it
     * @param string $key The `Idempotency-Key` header
     * @param string $requestHash Hash of the request (route, ids, body)
     * @param DateTimeImmutable $now When
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        #[ORM\Column(name: 'idempotency_key', length: self::MAX_KEY_LENGTH)]
        private string $key,
        #[ORM\Column(name: 'request_hash', length: 64)]
        private string $requestHash,
        DateTimeImmutable $now,
    ) {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->userRef   = ChatUserReferences::reference($user);
        $this->createdAt = $now;
    }

    /**
     * Doctrine identifier.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Who sent the key.
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * The stored reference of `user`, readable without loading the user.
     */
    public function getUserReference(): ?string
    {
        return $this->userRef ?? ChatUserReferences::reference($this->user);
    }

    /**
     * The key.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Hash of the request the key was first sent with.
     */
    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    /**
     * Uuids of the messages the request created (null while it runs).
     *
     * @return list<string>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * When the key was first sent.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
