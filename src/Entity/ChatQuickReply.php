<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatQuickReplyRepository;

use function mb_strlen;
use function trim;

/**
 * A saved snippet of one user (1.11): a title and a text inserted into the composer from the quick-replies menu.
 */
#[ORM\Entity(repositoryClass: ChatQuickReplyRepository::class)]
#[ORM\Table(name: 'nowo_chat_quick_reply')]
#[ORM\Index(name: 'idx_nowo_chat_quick_reply_user', columns: ['user_id'])]
class ChatQuickReply
{
    public const int TITLE_MAX = 80;

    public const int BODY_MAX = 2000;

    /** Snippets per user. */
    public const int MAX_PER_USER = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /**
     * Creates a snippet of the user.
     *
     * @param ChatUserInterface $user The owner
     * @param string $title Title (at most TITLE_MAX characters)
     * @param string $body Text (at most BODY_MAX characters)
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @throws InvalidArgumentException When the title or the text is empty or too long
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        string $title,
        string $body,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->change($title, $body);
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Changes the title and the text.
     *
     * @param string $title Title (at most TITLE_MAX characters)
     * @param string $body Text (at most BODY_MAX characters)
     *
     * @throws InvalidArgumentException When the title or the text is empty or too long
     */
    public function change(string $title, string $body): void
    {
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || $body === '' || mb_strlen($title) > self::TITLE_MAX || mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('invalid_quick_reply');
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->title = $title;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->body = $body;
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The owner.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * The title.
     *
     * @return string The title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * The text.
     *
     * @return string The text
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * When it was created.
     *
     * @return DateTimeImmutable The time
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The JSON shape.
     *
     * @return array{id: int|null, title: string, body: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body];
    }
}
