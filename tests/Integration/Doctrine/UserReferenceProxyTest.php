<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

/**
 * 1.30, audit QD-L4 (ported from the audit's PoC, inverted; the bug was in 1.29 too): in `id` mode the user reference
 * listener resolves the users of an initialized Doctrine proxy by its entity class. The GDPR export of a user while the
 * request holds one of their messages (its conversation a proxy) used to fail with a TypeError.
 */
final class UserReferenceProxyTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['user_reference' => 'id']]]];
    }

    public function testTheExportWorksWhileAMessageAndItsConversationProxyAreHeld(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $sent = $chat->send($ana, $dm, 'first');
        $chat->send($ana, $dm, 'second');
        $this->em->clear();
        $ana  = $this->em->getRepository(TestUser::class)->find($ana->getId()) ?? self::fail('Ana');
        $held = $this->em->getRepository(ChatMessage::class)->find($sent->getId()) ?? self::fail('message');
        // The held message's conversation is a proxy; reading it initializes it.
        self::assertSame($dm->getUuid(), $held->getConversation()->getUuid());

        $export = $this->get(ChatPersonalData::class)->export($ana);

        self::assertSame(['first', 'second'], array_column($export['messages'], 'text'));
    }

    public function testUnreadMentionsAreCountedByStoredReference(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'hi @[Luis](' . $luis->getChatPublicId() . ')');

        self::assertSame([(int) $dm->getId() => 1], $this->get(ChatMessageMentionRepository::class)->countUnreadByConversation($luis, [(int) $dm->getId()]));
        self::assertSame([], $this->get(ChatMessageMentionRepository::class)->countUnreadByConversation($ana, [(int) $dm->getId()]));
    }
}
