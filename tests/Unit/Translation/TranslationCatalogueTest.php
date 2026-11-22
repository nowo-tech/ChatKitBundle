<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist, and a value identical to
 * the English one (any length, ignoring values without letters once placeholders are removed) must be listed in
 * ALLOWED_IDENTICAL for that locale. An allow-list entry that is no longer identical to English is reported as stale.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/translations';

    /**
     * Keys that are correctly identical to English in a locale (brands, product and technical names, codes, loanwords
     * and cognates), per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_IDENTICAL = [
        'de' => [
            'chat.emoji',
            'chat.emoji_smileys',
            'chat.filter_support',
            'chat.format_code_button',
            'chat.forward_kind_support',
            'chat.links_panel',
            'chat.links_url',
            'chat.online',
            'chat.quick_reply_text',
            'chat.settings_sound_blip',
            'chat.settings_sound_ding',
            'chat.settings_sound_pop',
            'chat.settings_title',
            'chat.status_offline',
            'chat.support_thread',
            'chat.support_ticket_label',
            'chat.support_title',
            'chat.thread_title',
        ],
        'es' => [
            'chat.emoji',
            'chat.links_url',
            'chat.settings_sound_blip',
            'chat.settings_sound_ding',
            'chat.settings_sound_pop',
            'chat.settings_title',
            'chat.status_group_personal',
            'chat.status_invisible',
            'chat.support_ticket_label',
        ],
        'fr' => [
            'chat.auto_reply_text',
            'chat.emoji',
            'chat.filter_support',
            'chat.format_code',
            'chat.format_code_button',
            'chat.forward_kind_support',
            'chat.forward_targets',
            'chat.links_url',
            'chat.messages_label',
            'chat.page_title',
            'chat.settings_sound_ding',
            'chat.settings_sound_mention',
            'chat.settings_sound_pop',
            'chat.settings_volume',
            'chat.status_clear_30m',
            'chat.status_invisible',
            'chat.status_presets',
            'chat.support_thread',
            'chat.support_ticket_label',
            'chat.support_title',
            'chat_search.conversations.group',
            'chat_search.group',
            'support.topic.incident',
        ],
        'it' => [
            'chat.emoji',
            'chat.format_link_button',
            'chat.links_url',
            'chat.online',
            'chat.settings_sound_blip',
            'chat.settings_sound_ding',
            'chat.settings_sound_pop',
            'chat.settings_title',
            'chat.settings_volume',
            'chat.status_offline',
            'chat.support_ticket_label',
            'chat.thread_title',
        ],
        'nl' => [
            'chat.buzz',
            'chat.emoji',
            'chat.emoji_recent',
            'chat.emoji_smileys',
            'chat.filter_support',
            'chat.format_code',
            'chat.format_code_button',
            'chat.format_link_button',
            'chat.forward_kind_support',
            'chat.links_panel',
            'chat.links_url',
            'chat.online',
            'chat.people_recent',
            'chat.settings_sound_blip',
            'chat.settings_sound_ding',
            'chat.settings_sound_pop',
            'chat.settings_title',
            'chat.settings_volume',
            'chat.sidebar_recent',
            'chat.status_offline',
            'chat.status_preset_lunch',
            'chat.support_status_open',
            'chat.support_thread',
            'chat.support_ticket_label',
            'chat.support_title',
            'chat.thread_title',
            'chat.via_project',
            'chat_search.filters',
        ],
        'pt' => [
            'chat.emoji',
            'chat.links_panel',
            'chat.links_url',
            'chat.online',
            'chat.settings_sound_blip',
            'chat.settings_sound_ding',
            'chat.settings_sound_pop',
            'chat.settings_title',
            'chat.settings_volume',
            'chat.status_offline',
            'chat.support_ticket_label',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2})\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_IDENTICAL[$locale] ?? [];
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || in_array($key, $allowed, true)) {
                continue;
            }

            if (preg_match('/[^\W\d_]/u', (string) preg_replace('/%[^%\s]+%|\{[^}]+\}/', '', $value)) === 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_IDENTICAL as $locale => $keys) {
            foreach ($keys as $key) {
                if (!isset($english[$key]) || ($locales[$locale][$key] ?? null) !== $english[$key]) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Allow-list entries that no longer exist or are no longer identical to English');
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
