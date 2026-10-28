<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * @mentions end to end on SQLite: only members who may read the conversation are mentioned (strangers, disabled
 * accounts, the author and unknown ids stay plain text), one row per person, edits add / remove rows and notify only
 * the new people, reading the thread marks them read, deleting removes them, and the "mentions of me" queries only
 * list conversations the user may still read.
 */
final class ChatMentionsTest extends DatabaseTestCase
{
    /** @var list<ChatMentionEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(EventDispatcherInterface::class)->addListener(ChatMentionEvent::class, function (ChatMentionEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testParseIgnoresCodeAndKeepsTheFirstName(): void
    {
        self::assertSame(
            ['abc' => 'Ana', 'def' => 'Luis'],
            ChatMentions::parse('@[Ana](abc) `@[Code](zzz)` @[Luis](def) @[Other](abc) ```@[Block](yyy)``` @[bad name](no spaces!)'),
        );
        self::assertSame([], ChatMentions::parse('mail me at ana@example.test'));
    }

    public function testSendKeepsMembersOnlyAndDispatchesOnce(): void
    {
        $chat     = $this->get(ChatService::class);
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $eve      = $this->user('Eve [ops]');
        $stranger = $this->user('Stranger Sam');
        $disabled = $this->user('Disabled Bob');
        $channel  = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        $body = sprintf(
            'Hi %s and %s, %s, %s, %s, %s, again %s',
            $this->token('Lu', $luis),
            $this->token('Eve', $eve),
            $this->token('Sam', $stranger),
            $this->token('Bob', $disabled),
            $this->token('Me', $ana),
            '@[Ghost](unknown-id)',
            $this->token('Luis', $luis),
        );
        $message = $chat->send($ana, $channel, $body);

        self::assertSame(
            sprintf('Hi @[Luis](%s) and @[Eve ops](%s), @Sam, @Bob, @Me, @Ghost, again @[Luis](%1$s)', $luis->getChatPublicId(), $eve->getChatPublicId()),
            $message->getBody(),
            'members get their current name; strangers, disabled accounts, the author and unknown ids become text',
        );
        self::assertStringContainsString('<span class="nowo-chat-mention" data-mention="' . $luis->getChatPublicId() . '">@Luis</span>', $message->getBodyHtml());
        self::assertSame([(int) $luis->getId(), (int) $eve->getId()], $this->mentionedIds($message));
        self::assertCount(1, $this->events);
        self::assertSame($message, $this->events[0]->message);
        self::assertSame($channel, $this->events[0]->conversation);
        self::assertSame(['Luis', 'Eve [ops]'], array_map(static fn (ChatUserInterface $user): string => $user->getChatDisplayName(), $this->events[0]->users));
        self::assertFalse($this->events[0]->edited);

        // No mention: no event.
        $chat->send($ana, $channel, 'plain text');
        self::assertCount(1, $this->events);

        // Presenter: the mentions and whether the viewer is one of them.
        $presenter = $this->get(ChatPresenter::class);
        $shape     = $presenter->message($message, $luis);
        self::assertSame([['uuid' => $luis->getChatPublicId(), 'name' => 'Luis'], ['uuid' => $eve->getChatPublicId(), 'name' => 'Eve ops']], $shape['mentions']);
        self::assertTrue($shape['mentionsMe']);
        self::assertFalse($presenter->message($message, $ana)['mentionsMe']);
        self::assertFalse($presenter->message($message, null)['mentionsMe']);
        self::assertSame('Hi @Luis and @Eve ops, @Sam,…', $this->get(ChatMarkdownRenderer::class)->plainText($message->getBody(), 29));
    }

    public function testEditUpdatesRowsAndNotifiesOnlyNewPeople(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $eve     = $this->user('Eve');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $message = $chat->send($ana, $channel, 'ping ' . $this->token('Luis', $luis));
        self::assertCount(1, $this->events);

        $chat->edit($message, 'ping ' . $this->token('Luis', $luis) . ' ' . $this->token('Eve', $eve));
        self::assertCount(2, $this->events);
        self::assertTrue($this->events[1]->edited);
        self::assertSame([$eve], $this->events[1]->users, 'Luis was already notified');
        self::assertSame([(int) $luis->getId(), (int) $eve->getId()], $this->mentionedIds($message));

        $chat->edit($message, 'only ' . $this->token('Eve', $eve));
        self::assertCount(2, $this->events, 'nobody new');
        self::assertSame([(int) $eve->getId()], $this->mentionedIds($message));

        $chat->edit($message, 'nobody');
        self::assertSame([], $this->mentionedIds($message));

        $chat->edit($message, 'again ' . $this->token('Eve', $eve));
        $chat->delete($ana, $message);
        self::assertSame([], $this->mentionedIds($message), 'a deleted message mentions nobody');
        self::assertSame([], $this->get(ChatPresenter::class)->message($message, $eve)['mentions']);
    }

    public function testInboxQueriesReadStateAndVisibility(): void
    {
        $chat    = $this->get(ChatService::class);
        $repo    = $this->get(ChatMessageMentionRepository::class);
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $dm      = $chat->openDirect($ana, $luis);
        $support = $chat->openSupport($luis);
        $first   = $chat->send($ana, $channel, 'one ' . $this->token('Luis', $luis));
        $second  = $chat->send($ana, $dm, 'two ' . $this->token('Luis', $luis));
        $third   = $chat->send($agent, $support, 'three ' . $this->token('Luis', $luis));
        $agentDm = $chat->send($luis, $support, 'four ' . $this->token('Agent', $agent));
        $keys    = [TestChannelProvider::KEY];

        self::assertSame([$third->getId(), $second->getId(), $first->getId()], array_map(static fn (ChatMessageMention $m): ?int => $m->getMessage()->getId(), $repo->findForUser($luis, $keys, false, 10)));
        self::assertSame(3, $repo->countForUser($luis, $keys, false));
        self::assertSame(2, $repo->countForUser($luis, [], false), 'a channel the user left no longer counts');
        self::assertSame(1, $repo->countForUser($luis, $keys, false, channelsOnly: true));
        self::assertSame(0, $repo->countForUser($luis, [], false, channelsOnly: true));
        self::assertSame([], $repo->findForUser($luis, $keys, false, 0));
        self::assertSame(0, $repo->countForUser($luis, $keys, false, since: new DateTimeImmutable('+1 day')));
        self::assertSame(1, $repo->countForUser($agent, [], true), 'an agent sees mentions in any support thread');
        self::assertSame(0, $repo->countForUser($agent, [], false), 'a demoted agent no longer does');
        self::assertSame($agentDm->getId(), $repo->findForUser($agent, [], true, 5)[0]->getMessage()->getId());

        self::assertSame([(int) $channel->getId() => 1, (int) $dm->getId() => 1, (int) $support->getId() => 1], $repo->countUnreadByConversation($luis, [(int) $channel->getId(), (int) $dm->getId(), (int) $support->getId()]));
        self::assertSame([], $repo->countUnreadByConversation($luis, []));

        // Reading the DM marks its mention read; the other mentions stay unread.
        $chat->markRead($luis, $dm, (int) $second->getId());
        self::assertSame(2, $repo->countForUser($luis, $keys, false, unreadOnly: true));
        $mention = $repo->findForUser($luis, $keys, false, 10, unreadOnly: true)[0];
        self::assertSame($mention, $repo->findOneForUser($luis, (int) $mention->getId()));
        self::assertNull($repo->findOneForUser($ana, (int) $mention->getId()), 'someone else cannot reach it');
        self::assertTrue($mention->isUnread());
        $mention->markRead(new DateTimeImmutable('2026-01-01'));
        $mention->markRead(new DateTimeImmutable('2026-02-01'));
        self::assertSame('2026-01-01', $mention->getReadAt()?->format('Y-m-d'), 'the first read time stays');
        self::assertSame($luis, $mention->getUser());
    }

    public function testTokenSanitizesTheName(): void
    {
        $user = $this->user('  ');
        self::assertSame('@[?](' . $user->getChatPublicId() . ')', ChatMentions::token($user));
        $odd = $this->user("A]\n[B");
        self::assertSame('@[A B](' . $odd->getChatPublicId() . ')', ChatMentions::token($odd));
    }

    private function token(string $name, ChatUserInterface $user): string
    {
        return '@[' . $name . '](' . $user->getChatPublicId() . ')';
    }

    /**
     * @return list<int>
     */
    private function mentionedIds(ChatMessage $message): array
    {
        $ids = $this->get(ChatMessageMentionRepository::class)->userIdsOf($message);
        sort($ids);

        return $ids;
    }
}
