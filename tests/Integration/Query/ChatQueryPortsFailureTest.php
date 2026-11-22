<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Query;

use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatActorResolver;
use Nowo\ChatKitBundle\Security\ChatUuidAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use RuntimeException;

use function strtoupper;

/**
 * 2.2: the user ids of the host query ports. A directory that folds case or padding never hands back a look-alike user;
 * an id that cannot be one is not looked up; a failing directory (or store) is logged with its class only and answered as
 * the same refusal as an unknown id.
 */
final class ChatQueryPortsFailureTest extends DatabaseTestCase
{
    public function testAFoldingDirectoryNeverHandsBackALookAlike(): void
    {
        $ana    = $this->user('Ana');
        $asked  = [];
        $folded = new ChatActorResolver($this->directory(static function (string $publicId) use ($ana, &$asked): ChatUserInterface {
            $asked[] = $publicId;

            return $ana;
        }));

        self::assertSame($ana, $folded->resolve($ana->getChatPublicId()));
        self::assertSame($ana, $folded->resolve($ana), 'a user object is used as is');
        foreach ([strtoupper($ana->getChatPublicId()), $ana->getChatPublicId() . ' '] as $lookAlike) {
            self::assertNull($folded->resolve($lookAlike));
        }
        foreach (['', ' ', ' x', 'x ', "a\u{200B}b", "a\nb", "\xC3\x28", str_repeat('a', 256)] as $unusable) {
            self::assertFalse(ChatActorResolver::isUsable($unusable), 'unusable: ' . $unusable);
            self::assertNull($folded->resolve($unusable));
        }
        self::assertTrue(ChatActorResolver::isUsable('a'));
        self::assertTrue(ChatActorResolver::isUsable('Ana López 7'));
        self::assertTrue(ChatActorResolver::isUsable(str_repeat('a', 255)));
        self::assertSame([$ana->getChatPublicId(), strtoupper($ana->getChatPublicId())], $asked, 'only usable ids reach the directory (the padded one never)');
    }

    public function testAFailureIsLoggedByClassAndAnsweredAsARefusal(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $direct = $this->get(ChatMessagingInterface::class)->openDirect($ana, $luis);
        $logger = new RecordingLogger();
        $access = new ChatUuidAccess(
            $this->get(ChatConversationRepository::class),
            $this->get(ChatVoter::class),
            $this->get(ChatAccess::class),
            new ChatActorResolver($this->directory(static fn (string $publicId): ChatUserInterface => throw new RuntimeException('secret ' . $publicId))),
            true,
            $logger,
        );

        self::assertTrue($access->canView($ana, $direct->uuid), 'a user object needs no lookup');
        self::assertFalse($access->canView($ana->getChatPublicId(), $direct->uuid));
        self::assertSame([], $access->viewableConversationUuids($ana->getChatPublicId(), [$direct->uuid]));
        self::assertSame([$direct->uuid], $access->viewableConversationUuids($ana, [$direct->uuid]));
        self::assertFalse($access->canView($ana, ''));
        self::assertCount(2, $logger->lines);
        foreach ($logger->lines as $line) {
            self::assertStringContainsString(RuntimeException::class, $line);
            self::assertStringNotContainsString('secret', $line);
            self::assertStringNotContainsString($ana->getChatPublicId(), $line);
        }

        // Without a logger the refusal is the same.
        $quiet = new ChatUuidAccess($this->get(ChatConversationRepository::class), $this->get(ChatVoter::class), $this->get(ChatAccess::class), new ChatActorResolver($this->directory(static fn (string $publicId): ChatUserInterface => throw new RuntimeException('x'))));
        self::assertFalse($quiet->canPost($ana->getChatPublicId(), $direct->uuid));
    }

    public function testTheUsageQueriesOfNothingAskNothing(): void
    {
        $messages = $this->get(ChatMessageRepository::class);
        self::assertSame([], $messages->bodyLengthByChannelKey([]));
        self::assertSame([], $messages->bodyLengthByDirectAuthor([]));
    }

    /**
     * @param callable(string): ChatUserInterface $find
     */
    private function directory(callable $find): ChatDirectoryInterface
    {
        return new class($find) implements ChatDirectoryInterface {
            /** @var callable(string): ChatUserInterface */
            private $find;

            /**
             * @param callable(string): ChatUserInterface $find
             */
            public function __construct(callable $find)
            {
                $this->find = $find;
            }

            public function findByPublicId(string $publicId): ChatUserInterface
            {
                return ($this->find)($publicId);
            }

            public function findByIds(array $ids): array
            {
                return [];
            }

            public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
            {
                return [];
            }

            public function supportAgents(): array
            {
                return [];
            }
        };
    }
}
