<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Twig;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function trim;

/**
 * The launcher's accessible name is its translated label text (1.15.3, axe `button-name`): the icon is decorative
 * (aria-hidden) and the label stays in the markup (phones hide it visually with the sr-only pattern, never with
 * `display: none`).
 */
final class DockLauncherNameTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en', 'Messaging'];
        yield 'es' => ['es', 'Mensajes'];
    }

    #[DataProvider('locales')]
    public function testTheLauncherIsNamedByItsLabelAndTheIconIsHidden(string $locale, string $name): void
    {
        $request = Request::create('/');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->get(RequestStack::class)->push($request);

        $crawler  = new Crawler($this->twig()->render('@NowoChatKitBundle/_templates.html.twig'));
        $launcher = $crawler->filter('button.nowo-chat-launcher');
        self::assertCount(1, $launcher);
        self::assertSame($name, trim($launcher->filter('.nowo-chat-launcher__label')->text()));
        self::assertSame('true', $launcher->filter('.nowo-chat-launcher__icon')->attr('aria-hidden'));
        self::assertNull($launcher->attr('aria-label'), 'the visible (or sr-only) label names the button');
    }
}
