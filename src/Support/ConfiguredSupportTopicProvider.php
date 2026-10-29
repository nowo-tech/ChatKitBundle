<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Default topic provider: `support.topics` of the default profile. A label / reply left null becomes
 * `support.topic.<key>` / `support.topic_reply.<key>` in `support.translation_domain`; a reply "" posts none.
 */
final readonly class ConfiguredSupportTopicProvider implements ChatSupportTopicProviderInterface
{
    /**
     * Builds the provider from the configured topics.
     *
     * @param list<array{key: string, label: string|null, reply: string|null, icon: string|null, assignee: string|null}> $topics
     * @param string $domain Translation domain of the labels and replies
     */
    public function __construct(
        #[Autowire(param: 'nowo_chat_kit.support.topics')]
        private array $topics,
        #[Autowire(param: 'nowo_chat_kit.support.translation_domain')]
        private string $domain,
    ) {
    }

    /**
     * The configured topics, in order.
     *
     * @return list<ChatSupportTopic> The topics
     */
    public function topics(): array
    {
        return array_map(fn (array $topic): ChatSupportTopic => new ChatSupportTopic(
            $topic['key'],
            $topic['label'] ?? 'support.topic.' . $topic['key'],
            $topic['reply'] === '' ? null : ($topic['reply'] ?? 'support.topic_reply.' . $topic['key']),
            $topic['icon'],
            $topic['assignee'],
            $this->domain,
        ), $this->topics);
    }
}
