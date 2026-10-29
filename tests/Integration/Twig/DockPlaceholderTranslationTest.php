<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Twig;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\DataCollectorTranslator;

use function assert;

/**
 * The search boxes and the composer get their placeholders translated by the template in the dock locale: the form
 * theme must not look the translated text up again in the field domain (a "missing message" in the profiler, and a
 * lookup in the request locale instead of the dock one).
 */
final class DockPlaceholderTranslationTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en', ['Search conversations', 'Search people', '🙂', 'What is your status?', 'Write a message…']];
        yield 'es' => ['es', ['Buscar conversaciones', 'Buscar personas', '🙂', '¿Cuál es tu estado?', 'Escribe un mensaje…']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('locales')]
    public function testPlaceholdersAreTranslatedOnce(string $locale, array $expected): void
    {
        $request = Request::create('/');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->get(RequestStack::class)->push($request);

        $crawler = new Crawler($this->twig()->render('@NowoChatKitBundle/_templates.html.twig'));
        self::assertSame($expected, $crawler->filter('[placeholder]')->each(static fn (Crawler $node): string => (string) $node->attr('placeholder')));

        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $collector = $container->get(TestKernel::TRANSLATOR_COLLECTOR);
        assert($collector instanceof DataCollectorTranslator);
        $missing = array_filter(
            $collector->getCollectedMessages(),
            static fn (array $message): bool => $message['state'] === DataCollectorTranslator::MESSAGE_MISSING,
        );
        self::assertSame([], array_values(array_map(static fn (array $message): string => $message['locale'] . ' ' . $message['domain'] . ': ' . $message['id'], $missing)));
    }
}
