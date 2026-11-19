<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

/**
 * 1.37 (audit VA3-7, affects 1.9.0–1.36.1): a `#reference` title with a backtick opened a code span when rendered and
 * showed the stored title to every reader. New tokens never hold one; stored bodies render safely, including messages
 * whose stored HTML already shows the title.
 */
final class ChatReferenceBacktickTitleTest extends DatabaseTestCase
{
    private const string BODY = 'see #[Q`secretz](task:7) and `tail`';

    /** The render 1.9.0–1.36.1 stored for {@see self::BODY}. */
    private const string LEAKED_HTML = '<p>see #[Q<code>secretz](task:7) and </code>tail`</p>';

    public function testNewTokensNeverHoldABacktick(): void
    {
        self::assertSame('#[Fix foo bar](task:7)', ChatReferences::token(new ChatReference('task', '7', 'Fix `foo` bar', '/tasks/7')));
        self::assertSame('see #[Q secretz](task:7) and `tail`', ChatReferences::safeTitles(self::BODY));
        self::assertTrue(ChatReferences::hasUnsafeTitles(self::BODY));
        // Code spans and titles without a backtick are left as written.
        foreach (['plain text', 'a `#[Q`x` b', 'see #[Q secretz](task:7) and `tail`', '```#[Q`s](task:7)```'] as $body) {
            self::assertFalse(ChatReferences::hasUnsafeTitles($body), $body);
        }
    }

    public function testRenderAndPlainTextNeverShowTheTitle(): void
    {
        $markdown = $this->get(ChatMarkdownRenderer::class);
        $html     = $markdown->render(self::BODY);
        self::assertStringNotContainsString('secretz', $html);
        self::assertStringContainsString('<code>tail</code>', $html);
        self::assertStringNotContainsString('secretz', $markdown->plainText(self::BODY, 500));
        $ana = $this->user('Ana');
        self::assertStringNotContainsString('secretz', $this->get(ChatReferences::class)->plainText($markdown, self::BODY, $ana, 500));
    }

    public function testAStoredLeakingRenderIsReplaced(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $message = $chat->send($ana, $chat->openDirect($ana, $luis), 'placeholder');
        $other   = $chat->send($ana, $message->getConversation(), 'all **fine**');
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.body', ':body')->set('m.bodyHtml', ':html')->where('m.id = :id')
            ->setParameter('body', self::BODY)->setParameter('html', self::LEAKED_HTML)->setParameter('id', $message->getId())->getQuery()->execute();
        $this->em->clear();
        $stored = $this->em->find(ChatMessage::class, $message->getId());
        self::assertInstanceOf(ChatMessage::class, $stored);
        $reader = $this->em->find($luis::class, $luis->getId());
        self::assertNotNull($reader);

        $shown = (string) $this->get(ChatPresenter::class)->message($stored, $reader)['html'];
        self::assertStringNotContainsString('secretz', $shown);
        self::assertStringNotContainsString('secretz', $this->get(ChatMarkdownRenderer::class)->htmlOf($stored));
        self::assertStringNotContainsString('secretz', ChatMessageView::of($stored)->bodyHtml);
        // A message without such a token keeps its stored render.
        $fine = $this->em->find(ChatMessage::class, $other->getId());
        self::assertInstanceOf(ChatMessage::class, $fine);
        self::assertSame($fine->getBodyHtml(), $this->get(ChatMarkdownRenderer::class)->htmlOf($fine));
    }
}
