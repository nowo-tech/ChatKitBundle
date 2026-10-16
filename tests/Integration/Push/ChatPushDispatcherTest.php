<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Push;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\DeliverChatPushMessage;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Web Push selection at delivery time (push on, inline delivery without Messenger).
 */
final class ChatPushDispatcherTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['push' => true, 'push_url' => '/inbox?chat={uuid}', 'push_icon' => '/icon.png', 'push_preview' => true]]];
    }

    public function testOnlyParticipantsWhoWantItAndAreAway(): void
    {
        $chat   = $this->get(ChatService::class);
        $sender = $this->get(RecordingPushSender::class);
        $ana    = $this->user('Ana');
        $luis   = $this->enablePush($this->user('Luis'));
        $eve    = $this->enablePush($this->user('Eve'));
        $dm     = $chat->openDirect($ana, $luis);

        $message = $chat->send($ana, $dm, '**Deploy** at 5');
        self::assertSame([$luis->getChatPublicId()], array_keys($sender->sent), 'Ana wrote it; Eve is not in the conversation');
        self::assertSame([
            'event' => 'chat.message',
            'title' => 'Ana',
            'body'  => 'Deploy at 5',
            'tag'   => 'chat-' . $dm->getUuid(),
            'url'   => '/inbox?chat=' . $dm->getUuid(),
            'icon'  => '/icon.png',
            'badge' => '/icon.png',
        ], $sender->sent[$luis->getChatPublicId()]);

        // App open in a visible tab: no push.
        $sender->sent = [];
        $this->get(ChatPresence::class)->heartbeat($luis, true);
        $chat->send($ana, $dm, 'again');
        self::assertSame([], $sender->sent);
        $this->get(ChatPresence::class)->forget($luis);

        // Already read, muted, buzz refused, deleted message, unknown message: nothing.
        $dispatch = $this->get(ChatPushDispatcher::class);
        $chat->markRead($luis, $dm, (int) $message->getId());
        $dispatch(new DeliverChatPushMessage((int) $message->getId(), [(int) $luis->getId()]));
        $dispatch(new DeliverChatPushMessage(999_999, [(int) $luis->getId()]));
        $dispatch(new DeliverChatPushMessage((int) $message->getId(), []));
        self::assertSame([], $sender->sent);

        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($luis);
        $settings->setBuzzEnabled(false);
        $this->em->flush();
        $chat->buzz($ana, $dm);
        self::assertSame([], $sender->sent, 'buzzes refused');

        $chat->participant($dm, $luis)->muteUntil(new DateTimeImmutable('+1 hour'));
        $this->em->flush();
        $chat->send($ana, $dm, 'muted');
        self::assertSame([], $sender->sent);
        $chat->participant($dm, $luis)->muteUntil(null);
        $this->em->flush();

        $deleted      = $chat->send($ana, $dm, 'oops');
        $sender->sent = [];
        $chat->delete($ana, $deleted);
        $dispatch(new DeliverChatPushMessage((int) $deleted->getId(), [(int) $luis->getId()]));
        self::assertSame([], $sender->sent);

        $settings->setBuzzEnabled(true);
        $this->em->flush();
        $chat->buzz($ana, $dm);
        self::assertSame('Ana sent you a buzz', $this->get(RecordingPushSender::class)->sent[$luis->getChatPublicId()]['body'] ?? null);
        unset($eve);
    }

    public function testTitlesForChannelsAndSupportInTheRecipientsLocale(): void
    {
        $chat   = $this->get(ChatService::class);
        $sender = $this->get(RecordingPushSender::class);
        $ana    = $this->enablePush($this->user('Ana'));
        $ana->setChatLocale('es');
        $agent = $this->enablePush($this->user('Agent Smith'));
        $this->em->flush();

        $channel = $chat->openChannel($ana, $this->get(ChatAccess::class)->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $chat->openChannel($agent, $this->get(ChatAccess::class)->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $chat->send($agent, $channel, 'release today');
        self::assertSame('#Acme · Agent Smith', $sender->sent[$ana->getChatPublicId()]['title']);

        $support = $chat->openSupport($ana);
        $chat->participant($support, $agent);
        $this->em->flush();
        $chat->send($agent, $support, 'fixed');
        self::assertSame('Soporte', $sender->sent[$ana->getChatPublicId()]['title'], 'Spanish, from the agent side');
        $chat->send($ana, $support, 'thanks');
        self::assertSame('Ana', $sender->sent[$agent->getChatPublicId()]['title']);
    }

    private function enablePush(TestUser $user): TestUser
    {
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($user);
        $settings->setPushEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();

        return $user;
    }

    public function testGenericBodyWithoutPreview(): void
    {
        $sender = new RecordingPushSender();
        $chat   = $this->get(ChatService::class);
        $ana    = $this->user('Ana');
        $luis   = $this->enablePush($this->user('Luis'));
        $dm     = $chat->openDirect($ana, $luis);
        $this->get(ChatPushDispatcher::class); // container-built with push_preview: true (see chatKitConfig)

        $dispatcher = new ChatPushDispatcher(
            $this->get(ChatMessageRepository::class),
            $this->get(ChatParticipantRepository::class),
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatPresence::class),
            $this->get(ChatAccess::class),
            $this->get(ChatMarkdownRenderer::class),
            $this->get(TranslatorInterface::class),
            $sender,
        );
        $message = $chat->send($ana, $dm, 'salary review is on Friday');
        $dispatcher(new DeliverChatPushMessage((int) $message->getId(), [(int) $luis->getId()]));
        self::assertSame('New message', $sender->sent[$luis->getChatPublicId()]['body'] ?? null, 'CHAT-SEC-03: no message text on lock screens by default');
    }

    public function testSenderFailuresPropagateForMessengerRetries(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $luis    = $this->enablePush($this->user('Luis'));
        $dm      = $chat->openDirect($ana, $luis);
        $message = $chat->send($ana, $dm, 'hello');
        $failing = new class implements ChatPushSenderInterface {
            public function send(array $recipients, callable $payloadFor): void
            {
                throw new RuntimeException('push service down');
            }
        };
        $dispatcher = new ChatPushDispatcher(
            $this->get(ChatMessageRepository::class),
            $this->get(ChatParticipantRepository::class),
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatPresence::class),
            $this->get(ChatAccess::class),
            $this->get(ChatMarkdownRenderer::class),
            $this->get(TranslatorInterface::class),
            $failing,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('push service down');
        $dispatcher(new DeliverChatPushMessage((int) $message->getId(), [(int) $luis->getId()]));
    }
}
