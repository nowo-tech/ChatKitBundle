<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_starts_with;
use function substr;

/**
 * The support topics of the application (ChatSupportTopicProviderInterface) with their labels and replies translated
 * for the current locale. A ticket whose topic is gone from the list (or never had one) shows "No topic".
 *
 * Decorative emojis (`support.emojis`): one per ticket status, per topic and per assistant line, rendered in an
 * `aria-hidden` span before the text so screen readers read only the text. Translations carry none.
 */
final readonly class ChatSupportTopics
{
    /** Emoji per ticket status. */
    public const array STATUS_EMOJIS = [
        'open'     => '🟢',
        'waiting'  => '⏳',
        'resolved' => '✅',
        'closed'   => '🔒',
    ];

    /** Emoji per assistant line (`support.bot.<name>`); a topic's automatic reply uses the topic's emoji. */
    public const array BOT_EMOJIS = [
        'ticket_opened'   => '🎫',
        'topic_changed'   => '🏷️',
        'continued'       => '🔄',
        'status_open'     => '🔁',
        'status_waiting'  => '⏳',
        'status_resolved' => '✅',
        'status_closed'   => '🔒',
        'assigned'        => '🙋',
        'unassigned'      => '👤',
    ];

    /**
     * Builds the catalogue from the topic provider and the translator.
     *
     * @param ChatSupportTopicProviderInterface $provider The application's topics
     * @param TranslatorInterface $translator Translates labels, replies and the "No topic" fallback
     * @param bool $emojis Whether the decorative emojis are shown (`support.emojis`)
     */
    public function __construct(
        private ChatSupportTopicProviderInterface $provider,
        private TranslatorInterface $translator,
        #[Autowire(param: 'nowo_chat_kit.support.emojis')]
        private bool $emojis = true,
    ) {
    }

    /**
     * Whether the decorative emojis are shown.
     *
     * @return bool The `support.emojis` setting
     */
    public function emojis(): bool
    {
        return $this->emojis;
    }

    /**
     * Emoji of a topic key ("" when emojis are off, the topic has none or is unknown).
     *
     * @param string|null $key Topic key
     *
     * @return string The emoji
     */
    public function topicEmoji(?string $key): string
    {
        return $this->emojis ? ($this->find($key)->emoji ?? '') : '';
    }

    /**
     * Emoji of a ticket status ("" when emojis are off or the status is unknown).
     *
     * @param string $status Status value (open, waiting, resolved, closed)
     *
     * @return string The emoji
     */
    public function statusEmoji(string $status): string
    {
        return $this->emojis ? (self::STATUS_EMOJIS[$status] ?? '') : '';
    }

    /**
     * Emoji of an assistant line: by its `support.bot.<name>` key, else the topic's (a topic's automatic reply).
     *
     * @param string $key Translation key (or plain text) of the line
     * @param string|null $topic Topic key stored with the line
     *
     * @return string The emoji ("" when emojis are off or none applies)
     */
    public function botEmoji(string $key, ?string $topic): string
    {
        if (!$this->emojis) {
            return '';
        }
        if (str_starts_with($key, 'support.bot.')) {
            return self::BOT_EMOJIS[substr($key, 12)] ?? '';
        }

        return $this->topicEmoji($topic);
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
     * Topics for the client: key, translated label, icon name and emoji ("" when none or emojis are off).
     *
     * @return list<array{key: string, label: string, icon: string|null, emoji: string}> The options, in order
     */
    public function options(): array
    {
        return array_values(array_map(fn (ChatSupportTopic $topic): array => [
            'key'   => $topic->key,
            'label' => $this->text($topic->label, $topic->domain),
            'icon'  => $topic->icon,
            'emoji' => $this->emojis ? ($topic->emoji ?? '') : '',
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
