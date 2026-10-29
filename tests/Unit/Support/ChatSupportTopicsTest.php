<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Support;

use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;
use Nowo\ChatKitBundle\Support\ChatSupportTopics;
use Nowo\ChatKitBundle\Support\ConfiguredSupportTopicProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Configured topics get default label / reply keys (a reply "" posts none); topics managed as data (null domain) keep
 * their plain text; a duplicated key keeps its first topic; an unknown key shows itself.
 */
final class ChatSupportTopicsTest extends TestCase
{
    public function testConfiguredTopicsGetDefaultKeys(): void
    {
        $topics = (new ConfiguredSupportTopicProvider([
            ['key' => 'billing', 'label' => null, 'reply' => null, 'icon' => 'tabler:receipt', 'assignee' => 'agent-1'],
            ['key' => 'other', 'label' => 'my.label', 'reply' => '', 'icon' => null, 'assignee' => null],
        ], 'messages'))->topics();

        self::assertEquals(new ChatSupportTopic('billing', 'support.topic.billing', 'support.topic_reply.billing', 'tabler:receipt', 'agent-1', 'messages', '💳'), $topics[0]);
        self::assertEquals(new ChatSupportTopic('other', 'my.label', null, null, null, 'messages', '💬'), $topics[1]);
    }

    public function testConfiguredEmojisOverrideOrDropTheBuiltInOnes(): void
    {
        $topics = (new ConfiguredSupportTopicProvider([
            ['key' => 'billing', 'label' => null, 'reply' => null, 'icon' => null, 'assignee' => null, 'emoji' => '🧾'],
            ['key' => 'other', 'label' => null, 'reply' => null, 'icon' => null, 'assignee' => null, 'emoji' => ''],
            ['key' => 'sales', 'label' => null, 'reply' => null, 'icon' => null, 'assignee' => null],
        ], 'messages'))->topics();

        self::assertSame(['🧾', null, null], array_map(static fn (ChatSupportTopic $topic): ?string => $topic->emoji, $topics));
    }

    public function testEmojisPerStatusTopicAndAssistantLine(): void
    {
        $provider = new class implements ChatSupportTopicProviderInterface {
            public function topics(): array
            {
                return [new ChatSupportTopic('billing', 'Billing', emoji: '💳'), new ChatSupportTopic('plain', 'Plain')];
            }
        };
        $topics = new ChatSupportTopics($provider, new IdentityTranslator());

        self::assertTrue($topics->emojis());
        self::assertSame('💳', $topics->topicEmoji('billing'));
        self::assertSame('', $topics->topicEmoji('plain'));
        self::assertSame('', $topics->topicEmoji(null));
        self::assertSame('✅', $topics->statusEmoji('resolved'));
        self::assertSame('', $topics->statusEmoji('unknown'));
        self::assertSame('🎫', $topics->botEmoji('support.bot.ticket_opened', 'billing'));
        self::assertSame('', $topics->botEmoji('support.bot.unknown', 'billing'));
        self::assertSame('💳', $topics->botEmoji('support.topic_reply.billing', 'billing'), 'a topic reply uses the topic emoji');
        self::assertSame('💳', $topics->options()[0]['emoji']);

        $off = new ChatSupportTopics($provider, new IdentityTranslator(), false);
        self::assertFalse($off->emojis());
        self::assertSame(['', '', '', ''], [$off->topicEmoji('billing'), $off->statusEmoji('open'), $off->botEmoji('support.bot.continued', null), $off->options()[0]['emoji']]);
    }

    public function testPlainTextTopicsAndFallbacks(): void
    {
        $provider = new class implements ChatSupportTopicProviderInterface {
            public function topics(): array
            {
                return [
                    new ChatSupportTopic('sales', 'Sales %x%', 'Hi'),
                    new ChatSupportTopic('sales', 'Duplicate'),
                ];
            }
        };
        $topics = new ChatSupportTopics($provider, new IdentityTranslator());

        self::assertSame(['sales'], array_keys($topics->all()));
        self::assertSame('Sales %x%', $topics->label('sales'));
        self::assertSame('gone', $topics->label('gone'));
        self::assertSame('chat.support_no_topic', $topics->label(null));
        self::assertNull($topics->find(null));
        self::assertSame('Sales 1', $topics->text('Sales %x%', null, ['%x%' => '1']));
        self::assertSame([['key' => 'sales', 'label' => 'Sales %x%', 'icon' => null, 'emoji' => '']], $topics->options());
    }
}
