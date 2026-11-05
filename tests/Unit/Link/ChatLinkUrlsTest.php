<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Link;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Model\ChatLinkProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function function_exists;
use function str_repeat;

/**
 * 1.19: the URL rules of the links feature — https only (http when allowed), no other scheme, no user information,
 * normalised and percent-encoded; providers by hostname; the link card and the index extraction.
 */
final class ChatLinkUrlsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedLinks(): iterable
    {
        yield 'teams meeting' => [
            'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0?context=%7b%22Tid%22%3a%22x%22%7d',
            'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0?context=%7b%22Tid%22%3a%22x%22%7d',
        ];
        yield 'uppercase scheme and host, default port, no path' => ['HTTPS://Meet.Google.COM:443', 'https://meet.google.com/'];
        yield 'other port kept' => ['https://example.com:8443/a', 'https://example.com:8443/a'];
        yield 'quotes, parentheses and spaces are encoded' => ["https://example.com/a(b)'c\"<d>", 'https://example.com/a%28b%29%27c%22%3Cd%3E'];
        yield 'non-ascii path and stray percent' => ['https://example.com/ñ?q=100%&x=%41', 'https://example.com/%C3%B1?q=100%25&x=%41'];
        yield 'fragment' => ['https://example.com/#top', 'https://example.com/#top'];
        yield 'surrounding whitespace' => ['  https://example.com/x  ', 'https://example.com/x'];
    }

    #[DataProvider('acceptedLinks')]
    public function testNormalizesAcceptedLinks(string $given, string $expected): void
    {
        self::assertSame($expected, (new ChatLinkUrls())->normalize($given));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'http by default' => ['http://example.com/'];
        yield 'protocol-relative' => ['//example.com/'];
        yield 'relative' => ['/path'];
        yield 'ftp' => ['ftp://example.com/'];
        yield 'userinfo' => ['https://user:pass@example.com/'];
        yield 'user only' => ['https://user@example.com/'];
        yield 'whitespace inside' => ['https://exa mple.com/'];
        yield 'backslash' => ['https://example.com\\@evil.test/'];
        yield 'control character' => ["https://example.com/\x01"];
        yield 'empty' => ['   '];
        yield 'no host' => ['https:///path'];
        yield 'bad host' => ['https://-bad-.com/'];
        yield 'ipv6' => ['https://[::1]/'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', ChatLinkUrls::URL_MAX_LENGTH)];
        yield 'too long once encoded' => ['https://example.com/' . str_repeat('"', 700)];
        yield 'scheme not at the start' => ['xhttps://example.com/'];
    }

    #[DataProvider('refusedLinks')]
    public function testRefusesOtherLinks(string $given): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_link_url');

        (new ChatLinkUrls())->normalize($given);
    }

    public function testHttpWhenTheHostOptsIn(): void
    {
        $urls = new ChatLinkUrls(allowHttp: true);

        self::assertTrue($urls->allowsHttp());
        self::assertSame('http://intranet.example/', $urls->normalize('http://intranet.example:80'));
        self::assertFalse((new ChatLinkUrls())->allowsHttp());
    }

    public function testInternationalHostnames(): void
    {
        $urls = new ChatLinkUrls();
        if (!function_exists('idn_to_ascii')) {
            $this->expectException(InvalidArgumentException::class);
        }

        self::assertSame('https://xn--bcher-kva.example/', $urls->normalize('https://bücher.example/'));
    }

    public function testTitles(): void
    {
        $urls = new ChatLinkUrls();

        self::assertNull($urls->title(null));
        self::assertNull($urls->title("  \u{202E} "));
        self::assertSame('Daily stand-up', $urls->title("  Daily\n  stand-up\u{202E} "));
        self::assertSame(str_repeat('a', 120), $urls->title(str_repeat('a', 120)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_link_title');
        $urls->title(str_repeat('a', 121));
    }

    public function testProvidersMatchTheHostnameOnly(): void
    {
        $urls = new ChatLinkUrls();

        self::assertSame(['teams', 'meet', 'zoom', 'jitsi', 'webex'], array_map(static fn (ChatLinkProvider $provider): string => $provider->key, $urls->providers()));
        self::assertSame('teams', $urls->provider('teams.microsoft.com')?->key);
        self::assertSame('teams', $urls->provider('teams.live.com')?->key);
        self::assertSame('zoom', $urls->provider('us02web.zoom.us')?->key, 'a subdomain matches');
        self::assertNull($urls->provider('notzoom.us'), 'a suffix that is not a subdomain does not');
        self::assertNull($urls->provider('example.com'));
        self::assertNull($urls->provider('example.com.teams.microsoft.com.evil.test'));
        self::assertSame('Google Meet', $urls->providerByKey('meet')?->label);
        self::assertNull($urls->providerByKey('nope'));
        self::assertSame(['key' => 'teams', 'label' => 'Microsoft Teams', 'icon' => 'provider_teams', 'fallbackIcon' => 'video'], $urls->providerView('teams.microsoft.com'));
        self::assertNull($urls->providerView('example.com'));
        self::assertSame('Daily', $urls->label('Daily', 'teams.microsoft.com'));
        self::assertSame('Microsoft Teams', $urls->label(null, 'teams.microsoft.com'));
        self::assertSame('example.com', $urls->label(null, 'example.com'));
        self::assertSame('example.com', $urls->host('https://Example.com/x'));
        self::assertSame('', $urls->host('nothing'));

        $custom = new ChatLinkUrls(['whereby' => ['label' => 'Whereby', 'hosts' => [' Whereby.com ']]]);
        self::assertSame('video', $custom->provider('team.whereby.com')?->fallbackIcon);
        self::assertSame(['whereby.com'], $custom->providers()[0]->hosts);
    }

    public function testCardEscapesEverything(): void
    {
        $urls = new ChatLinkUrls();

        self::assertSame(
            '<a class="nowo-chat-linkcard" href="https://teams.microsoft.com/l/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer nofollow ugc" data-title="&lt;b&gt;Daily&lt;/b&gt;" data-provider="teams">'
            . '<span class="nowo-chat-linkcard__icon" data-icon="provider_teams" data-icon-fallback="video" data-icon-size="18" aria-hidden="true"></span>'
            . '<span class="nowo-chat-linkcard__text"><span class="nowo-chat-linkcard__title">&lt;b&gt;Daily&lt;/b&gt;</span>'
            . '<span class="nowo-chat-linkcard__meta">Microsoft Teams · teams.microsoft.com</span></span></a>',
            $urls->card('https://teams.microsoft.com/l/x?a=1&b=2', '<b>Daily</b>'),
        );
        self::assertSame(
            '<a class="nowo-chat-linkcard" href="https://example.com/" target="_blank" rel="noopener noreferrer nofollow ugc" data-title="">'
            . '<span class="nowo-chat-linkcard__icon" data-icon="link" data-icon-size="18" aria-hidden="true"></span>'
            . '<span class="nowo-chat-linkcard__text"><span class="nowo-chat-linkcard__title">example.com</span>'
            . '<span class="nowo-chat-linkcard__meta">example.com</span></span></a>',
            $urls->card('https://example.com/', null),
        );
    }

    public function testNormalizeCardsOutsideCode(): void
    {
        $urls = new ChatLinkUrls();

        self::assertSame(
            "Join: !link[Daily](https://teams.microsoft.com/l/x) `!link[x](http://code.example)`\n```\n!link[](http://block.example)\n```",
            $urls->normalizeCards("Join: !link[ Daily ](HTTPS://Teams.Microsoft.com/l/x) `!link[x](http://code.example)`\n```\n!link[](http://block.example)\n```"),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_link');
        $urls->normalizeCards('!link[x](http://example.com/)');
    }

    public function testExtractsTheLinksOfAMessage(): void
    {
        $urls = new ChatLinkUrls();
        $body = "!link[Daily](https://teams.microsoft.com/l/x) and [the doc](https://docs.example/d) or www.example.org/page.\n"
            . "Again https://teams.microsoft.com/l/x, bad http://plain.example/ and `https://code.example/` and @[Ana](u1) #[Task](task:1)\n"
            . "```\nhttps://block.example/\n```\n"
            . '[' . str_repeat('t', 150) . '](https://long.example/)';

        self::assertSame([
            ['url' => 'https://teams.microsoft.com/l/x', 'title' => 'Daily', 'kind' => 'card'],
            ['url' => 'https://docs.example/d', 'title' => 'the doc', 'kind' => 'link'],
            ['url' => 'https://long.example/', 'title' => str_repeat('t', 120), 'kind' => 'link'],
            ['url' => 'https://www.example.org/page', 'title' => null, 'kind' => 'link'],
        ], $urls->extract($body));

        $many = '';
        for ($i = 0; $i < 30; ++$i) {
            $many .= ' https://example.com/' . $i;
        }
        self::assertCount(ChatLinkUrls::MAX_PER_MESSAGE, $urls->extract($many));
        self::assertSame([], $urls->extract('no links here'));
    }
}
