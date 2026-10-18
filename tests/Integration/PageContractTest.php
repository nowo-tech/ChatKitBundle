<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration;

use Nowo\ChatKitBundle\View\ChatPage;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;

use function dirname;

/**
 * REQ-UI-003 contract test of the full-page chat (unique page `page.html.twig`): list + open conversation, empty
 * list, read-only conversation — rendered against the UiKit stub host layout and stub theme.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $luis = self::conversation('7f0c1d52-8d1b-4c3e-9d65-0d7f7a3b9a11', 'Luis Pérez', 'luis@example.com', 2, 'See you at 10');
        $ops  = self::conversation('1c9e5b0a-3f2d-4b7a-8e61-5a4d2c1b0f22', '#ops', 'Channel', 0, 'Deploy done');

        yield 'conversation open' => new PageFixture('@NowoChatKitBundle/page.html.twig', self::context([
            'conversations' => [$luis, $ops],
            'current'       => $luis,
            'messages'      => [
                self::message('Hola **Ana**', false, 'Luis Pérez'),
                self::message('See you at 10', true, 'Ana'),
            ],
            'read'     => ['url' => '/chat/api/conversations/x/read', 'messageId' => 2, 'csrf' => 'tok'],
            'can_send' => true,
            'composer' => self::composer(),
        ]));
        yield 'no conversations' => new PageFixture('@NowoChatKitBundle/page.html.twig', self::context());
        yield 'read only' => new PageFixture('@NowoChatKitBundle/page.html.twig', self::context([
            'conversations' => [$ops],
            'current'       => $ops,
            'messages'      => [],
        ]));
    }

    protected static function templatePaths(): array
    {
        return ['NowoChatKitBundle' => dirname(__DIR__, 2) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function context(array $context = []): array
    {
        return $context + [
            'layout'        => null,
            'header'        => ChatPage::header(),
            'bundle'        => ChatPage::BUNDLE,
            'conversations' => [],
            'current'       => null,
            'messages'      => [],
            'read'          => null,
            'can_send'      => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function conversation(string $uuid, string $title, string $subtitle, int $unread, string $preview): array
    {
        return ['uuid' => $uuid, 'title' => $title, 'subtitle' => $subtitle, 'unread' => $unread, 'lastMessage' => ['preview' => $preview]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $html, bool $mine, string $author): array
    {
        return [
            'html'      => $html, 'mine' => $mine, 'authorName' => $author, 'kind' => 'text', 'deleted' => false,
            'moderated' => false, 'edited' => $mine, 'createdAt' => '2026-10-18T09:30:00+00:00',
        ];
    }

    private static function composer(): FormView
    {
        return Forms::createFormFactory()->createNamedBuilder('nowo_chat_message')
            ->add('body', TextareaType::class, ['label' => 'chat.composer_placeholder', 'translation_domain' => 'NowoChatKitBundle'])
            ->getForm()
            ->createView();
    }
}
