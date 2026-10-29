<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The support topics of the application (ChatSupportTopicProviderInterface) with their labels and replies translated
 * for the current locale. A ticket whose topic is gone from the list (or never had one) shows "No topic".
 */
final readonly class ChatSupportTopics
{
    /**
     * Builds the catalogue from the topic provider and the translator.
     *
     * @param ChatSupportTopicProviderInterface $provider The application's topics
     * @param TranslatorInterface $translator Translates labels, replies and the "No topic" fallback
     */
    public function __construct(
        private ChatSupportTopicProviderInterface $provider,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Every topic, keyed by its key, in display order.
     *
     * @return array<string, ChatSupportTopic> The topics
     */
    public function all(): array
    {
        $topics = [];
        foreach ($this->provider->topics() as $topic) {
            $topics[$topic->key] ??= $topic;
        }

        return $topics;
    }

    /**
     * The topic of a key.
     *
     * @param string|null $key Topic key
     *
     * @return ChatSupportTopic|null The topic; null when unknown or null
     */
    public function find(?string $key): ?ChatSupportTopic
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /**
     * Translated label of a topic key ("No topic" for null; the key itself when the topic is gone).
     *
     * @param string|null $key Topic key
     *
     * @return string The label
     */
    public function label(?string $key): string
    {
        if ($key === null) {
            return $this->translator->trans('chat.support_no_topic', [], ChatPresenter::DOMAIN);
        }
        $topic = $this->find($key);

        return $topic instanceof ChatSupportTopic ? $this->text($topic->label, $topic->domain) : $key;
    }

    /**
     * Topics for the client: key, translated label and icon name.
     *
     * @return list<array{key: string, label: string, icon: string|null}> The options, in order
     */
    public function options(): array
    {
        return array_values(array_map(fn (ChatSupportTopic $topic): array => [
            'key'   => $topic->key,
            'label' => $this->text($topic->label, $topic->domain),
            'icon'  => $topic->icon,
        ], $this->all()));
    }

    /**
     * Translates a key in a domain, or returns plain text as it is (null domain).
     *
     * @param string $text Translation key or plain text
     * @param string|null $domain Domain of the key; null for plain text
     * @param array<string, string> $params Translation parameters
     *
     * @return string The text
     */
    public function text(string $text, ?string $domain, array $params = []): string
    {
        return $domain === null ? strtr($text, $params) : $this->translator->trans($text, $params, $domain);
    }
}
