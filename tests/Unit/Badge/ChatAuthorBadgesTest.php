<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Badge;

use LogicException;
use Nowo\ChatKitBundle\Badge\ChatAuthorBadges;
use Nowo\ChatKitBundle\Badge\NoChatAuthorBadges;
use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ErasedChatUser;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAuthorBadgeProvider;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

/**
 * 1.29: the request memo of author badges — one call per batch, exact keys, nothing kept across requests.
 */
final class ChatAuthorBadgesTest extends TestCase
{
    public function testTheDefaultProviderIsNeverAsked(): void
    {
        $provider = new class implements ChatAuthorBadgeProviderInterface {
            public function badgesForMany(array $authors): array
            {
                throw new LogicException('never asked');
            }
        };
        self::assertTrue((new ChatAuthorBadges($provider))->enabled());

        $badges = new ChatAuthorBadges(new NoChatAuthorBadges());
        self::assertFalse($badges->enabled());
        $badges->prime([$this->message($this->person('bob'))]);
        self::assertSame([], $badges->of($this->message($this->person('bob'))));
        self::assertSame([], (new NoChatAuthorBadges())->badgesForMany([new ChatAuthorRef('bob', 'pub-bob')]));
    }

    public function testOneCallPerBatchWithDistinctAuthorsThenTheMemo(): void
    {
        $provider         = new TestAuthorBadgeProvider();
        $agent            = new ChatAuthorBadge('Agent', 'agent');
        $provider->badges = ['bot' => [$agent]];
        $badges           = new ChatAuthorBadges($provider);
        $bot              = $this->person('bot');
        $ana              = $this->person('ana');

        $badges->prime([$this->message($bot), $this->message($ana), $this->message($bot)]);
        self::assertSame([['bot', 'ana']], $provider->calls);
        self::assertSame([['pub-bot', 'pub-ana']], $provider->publicIds);
        self::assertSame([$agent], $badges->of($this->message($bot)));
        self::assertSame([], $badges->of($this->message($ana)));
        $badges->prime([$this->message($bot)]);
        self::assertCount(1, $provider->calls, 'known authors are not asked again');

        // A message that was not primed: one call for its author.
        self::assertSame([], $badges->of($this->message($this->person('luis'))));
        self::assertSame([['bot', 'ana'], ['luis']], $provider->calls);

        // The next request asks again.
        $badges->reset();
        $provider->badges = [];
        self::assertSame([], $badges->of($this->message($bot)));
        self::assertSame([['bot', 'ana'], ['luis'], ['bot']], $provider->calls);
    }

    public function testABadgeNeverMovesToALookAlike(): void
    {
        $provider         = new TestAuthorBadgeProvider();
        $agent            = new ChatAuthorBadge('Agent', 'agent');
        $provider->badges = ['Bob' => [$agent]];
        // A sloppy host also answers keys it was not asked about (folded ones).
        $provider->extra = ['bob' => [$agent], 'bób' => [$agent], 'Bob ' => [$agent]];
        $badges          = new ChatAuthorBadges($provider);
        $bigB            = $this->person('Bob');

        $badges->prime([$this->message($bigB)]);
        self::assertSame([$agent], $badges->of($this->message($bigB)));

        $provider->extra = [];
        foreach (['bob', 'bób', 'BOB'] as $lookAlike) {
            self::assertSame([], $badges->of($this->message($this->person($lookAlike))), $lookAlike . ' is another person');
        }
        self::assertSame([['Bob'], ['bob'], ['bób'], ['BOB']], $provider->calls, 'each look-alike is asked by its own exact key');
    }

    public function testAMessageWhoseLoadedAuthorIsNotTheStoredOneGetsNoBadge(): void
    {
        $provider         = new TestAuthorBadgeProvider();
        $provider->badges = ['bob' => [new ChatAuthorBadge('Agent', 'agent')]];
        $badges           = new ChatAuthorBadges($provider);
        $message          = $this->message($this->person('bob'));
        // The row says "Bob"; a folding directory handed back "bob".
        (new ReflectionProperty(ChatMessage::class, 'authorRef'))->setValue($message, 'Bob');

        $badges->prime([$message]);
        self::assertSame([], $badges->of($message));
        self::assertSame([], $provider->calls);
    }

    public function testLinesGoneAndErasedAuthorsAreNeverAsked(): void
    {
        $provider = new TestAuthorBadgeProvider();
        $badges   = new ChatAuthorBadges($provider);
        $channel  = ChatConversation::channel('project:acme');
        $messages = [
            ChatMessage::bot($channel, 'chat.bot', null),
            ChatMessage::system($channel, 'chat.system'),
            new ChatMessage($channel, null, 'x', 'x'),
            new ChatMessage($channel, new UnresolvedChatUser('gone', 'secret'), 'x', 'x'),
            new ChatMessage($channel, new ErasedChatUser(7, 'pub-erased'), 'x', 'x'),
        ];
        // A loaded author whose reference the port cannot carry (too long): no badge, no call.
        $long = new ChatMessage($channel, new RefChatUser('long', 'pub-long', 'Long'), 'x', 'x');
        (new ReflectionProperty(ChatMessage::class, 'author'))->setValue($long, new RefChatUser(str_repeat('a', 65), 'pub-long', 'Long'));
        $messages[] = $long;
        $badges->prime($messages);
        foreach ($messages as $message) {
            self::assertSame([], $badges->of($message));
        }
        self::assertSame([], $provider->calls);
    }

    public function testAFailingProviderGivesNoBadgeAndAWarningWithoutTheMessage(): void
    {
        $provider         = new TestAuthorBadgeProvider();
        $provider->badges = ['bot' => [new ChatAuthorBadge('Agent', 'agent')]];
        $provider->fail   = true;
        $logger           = new RecordingLogger();
        $badges           = new ChatAuthorBadges($provider, $logger);

        self::assertSame([], $badges->of($this->message($this->person('bot'))));
        self::assertSame([], $badges->of($this->message($this->person('bot'))), 'the failure is memoised for the request');
        self::assertCount(1, $provider->calls);
        self::assertCount(1, $logger->lines);
        self::assertStringStartsWith('warning Chat author badge provider failed', $logger->lines[0]);
        self::assertStringContainsString('RuntimeException', $logger->lines[0]);
        self::assertStringNotContainsString('directory down', $logger->lines[0]);
    }

    private function person(string $reference): ChatUserInterface
    {
        return new RefChatUser($reference, 'pub-' . $reference, $reference);
    }

    private function message(ChatUserInterface $author): ChatMessage
    {
        return new ChatMessage(ChatConversation::channel('project:acme'), $author, 'hi', '<p>hi</p>');
    }
}
