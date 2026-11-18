<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;
use Nowo\ChatKitBundle\Repository\ChatSavedMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\Console\Tester\CommandTester;

use function array_column;

/**
 * 1.35: the message menu's service and repositories directly — the Saved list's paging past hidden rows, read marks
 * (compaction, purge), the DDD façade by uuid, the voter's new attributes, and GDPR export / erasure of saved messages
 * and read times.
 */
final class ChatMessageActionsTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => [
            'save'         => ['enabled' => true, 'max_per_user' => 0],
            'message_info' => ['enabled' => true],
        ]]]];
    }

    public function testSavedPagesSkipHiddenRowsAndPage(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $actions = $this->get(ChatMessageActions::class);
        $dm      = $chat->openDirect($ana, $luis);
        $saved   = [];
        for ($i = 0; $i < ChatMessageActions::SAVED_PAGE + 5; ++$i) {
            $message = $chat->send($luis, $dm, 'm' . $i);
            self::assertTrue($actions->setSaved($ana, $message, true));
            $saved[] = $message;
        }
        // Deleted messages are hidden: the first page still fills up from older rows.
        foreach ([$saved[34], $saved[33], $saved[32]] as $gone) {
            $chat->delete($luis, $gone);
        }
        $first = $actions->savedPage($ana, null);
        self::assertCount(ChatMessageActions::SAVED_PAGE, $first['rows']);
        self::assertSame('m31', $first['rows'][0]->getMessage()->getBody());
        self::assertNotNull($first['next']);
        $second = $actions->savedPage($ana, $first['next']);
        self::assertSame(['m1', 'm0'], array_map(static fn (ChatSavedMessage $row): string => $row->getMessage()->getBody(), $second['rows']));
        self::assertNull($second['next']);
        // The uuid lookup of a page; nothing for an empty page.
        self::assertSame([$saved[0]->getUuid()], $actions->savedUuids($ana, [$saved[0]]));
        self::assertSame([], $actions->savedUuids($ana, []));
        self::assertSame([], $this->get(ChatSavedMessageRepository::class)->savedAmong($ana, []));
        // Unsaving a message that is not saved changes nothing.
        $other = $chat->send($luis, $dm, 'not saved');
        self::assertFalse($actions->setSaved($ana, $other, false));
        // Lines and bot messages are never saved.
        $this->expectExceptionMessage('not_savable');
        $actions->setSaved($ana, ChatMessage::system($dm, 'x'), true);
    }

    public function testMarkUnreadCreatesTheRowAndInfoLimits(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $chat    = $this->get(ChatService::class);
        $actions = $this->get(ChatMessageActions::class);
        $support = $chat->openSupport($ana);
        $asked   = $chat->send($ana, $support, 'help');
        $more    = $chat->send($ana, $support, 'more');
        // The agent never opened the ticket: the row is created; nothing was read, so nothing moves.
        self::assertNull($actions->markUnreadFrom($agent, $more));
        $row = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $support, 'user' => $agent]);
        self::assertInstanceOf(ChatParticipant::class, $row);
        // Info: the agent (moderator) may read the info of Ana's message; Luis's own rule; lines never.
        self::assertTrue($actions->mayReadInfo($agent, $asked));
        self::assertFalse($actions->mayReadInfo($luis, $asked));
        self::assertFalse($actions->mayReadInfo($ana, ChatMessage::system($support, 'x')));
        $chat->delete($ana, $more);
        self::assertFalse($actions->mayReadInfo($ana, $more));
        self::assertSame(0, $actions->shareReadTimesChanged($ana, true));
        // A bot line has no author: nobody is listed as its author.
        $bot = ChatMessage::bot($support, 'chat.support_greeting', 'NowoChatKitBundle');
        $this->em->persist($bot);
        $this->em->flush();
        self::assertSame('people', $actions->info($agent, $bot)['mode']);
    }

    public function testReadMarksCompactAndPurge(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $one  = $chat->send($ana, $dm, 'one');
        $two  = $chat->send($ana, $dm, 'two');
        $chat->markRead($luis, $dm, (int) $one->getId());
        // A second advance within a minute extends the same row.
        $chat->markRead($luis, $dm, (int) $two->getId());
        $marks = $this->marksOf($luis);
        self::assertCount(1, $marks);
        self::assertSame($two->getId(), $marks[0]->getUpToMessageId());
        $repository  = $this->get(ChatReadMarkRepository::class);
        $participant = $marks[0]->getParticipant();
        self::assertArrayHasKey((int) $participant->getId(), $repository->readTimes([(int) $participant->getId()], (int) $one->getId()));
        self::assertSame([], $repository->readTimes([], 1));
        self::assertSame(0, $repository->deleteFor([]));
        $repository->deleteIds([]);
        // A row older than a minute: the next advance adds one.
        $meta = $this->em->getClassMetadata(ChatReadMark::class);
        $this->em->getConnection()->update($meta->getTableName(), [$meta->getColumnName('readAt') => '2020-01-01 00:00:00'], [$meta->getColumnName('id') => $marks[0]->getId()]);
        $this->em->clear();
        $three = $chat->send($this->fresh($ana), $this->fresh($dm), 'three');
        $chat->markRead($this->fresh($luis), $this->fresh($dm), (int) $three->getId());
        self::assertCount(2, $this->marksOf($luis));

        // The purge removes read times older than the retention.
        $tester = new CommandTester($this->get(ChatPurgeCommand::class));
        $tester->execute([]);
        self::assertCount(1, $this->marksOf($luis));
        self::assertSame(1, $repository->deleteFor([(int) $participant->getId()]));
    }

    public function testTheDddFacadeAndTheVoter(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $bea    = $this->user('Bea');
        $chat   = $this->get(ChatService::class);
        $dm     = $chat->openDirect($ana, $luis);
        $his    = $chat->send($luis, $dm, 'his');
        $mine   = $chat->send($ana, $dm, 'mine');
        $menu   = $this->get(ChatMessageActionsInterface::class);
        $voter  = new ChatVoter($this->get(ChatAccess::class));
        $menuOn = $this->get(ChatVoter::class);

        self::assertTrue($menu->setSaved($ana, $dm->getUuid(), $his->getUuid(), true));
        $list = $menu->savedMessages($ana);
        self::assertSame([$his->getUuid()], array_column(array_column($list['items'], 'message'), 'uuid'));
        self::assertNull($list['next']);
        // From the first message: nothing is read any more.
        self::assertNull($menu->markUnreadFrom($ana, $dm->getUuid(), $his->getUuid()));
        self::assertSame('people', $menu->messageInfo($ana, $dm->getUuid(), $mine->getUuid())['mode']);
        // Unknown and forbidden are the same answer.
        foreach ([[$bea, $dm->getUuid(), $his->getUuid()], [$ana, '00000000-0000-4000-8000-000000000000', $his->getUuid()], [$ana, $dm->getUuid(), '00000000-0000-4000-8000-000000000000'], [$ana, $dm->getUuid(), $his->getUuid()]] as [$who, $c, $m]) {
            try {
                $menu->messageInfo($who, $c, $m);
                self::fail('allowed');
            } catch (ChatNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
        // ChatMessagingInterface::can() knows the new actions.
        $messaging = $this->get(ChatMessagingInterface::class);
        self::assertTrue($messaging->can($ana, $dm->getUuid(), ChatMessagingInterface::SAVE, $his->getUuid()));
        self::assertTrue($messaging->can($ana, $dm->getUuid(), ChatMessagingInterface::MARK_UNREAD, $his->getUuid()));
        self::assertFalse($messaging->can($ana, $dm->getUuid(), ChatMessagingInterface::MARK_UNREAD, $mine->getUuid()));
        self::assertTrue($messaging->can($ana, $dm->getUuid(), ChatMessagingInterface::INFO, $mine->getUuid()));
        // A voter built without the menu grants none of them.
        foreach ([ChatVoter::SAVE, ChatVoter::MARK_UNREAD, ChatVoter::INFO] as $attribute) {
            self::assertFalse($voter->decide($attribute, $his, $ana));
        }
        self::assertTrue($menuOn->decide(ChatVoter::SAVE, $his, $ana));
    }

    public function testExportAndErasure(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $chat    = $this->get(ChatService::class);
        $actions = $this->get(ChatMessageActions::class);
        $data    = $this->get(ChatPersonalData::class);
        $dm      = $chat->openDirect($ana, $luis);
        $his     = $chat->send($luis, $dm, 'his');
        $actions->setSaved($ana, $his, true);
        $chat->markRead($ana, $dm, (int) $his->getId());
        // Ana's own ticket: the agent saved one of its messages and read it.
        $ticket = $this->get(ChatSupportTickets::class)->open($ana);
        $asked  = $chat->send($ana, $ticket, 'help');
        $actions->setSaved($agent, $asked, true);
        $chat->markRead($agent, $ticket, (int) $asked->getId());

        $export = $data->export($ana);
        self::assertSame([$his->getUuid()], array_column($export['saved'], 'message_uuid'));
        self::assertSame($dm->getUuid(), $export['read_marks'][0]['conversation_uuid'] ?? null);
        self::assertTrue($export['settings']['share_read_times']);
        self::assertSame([], $data->export($this->user('Nobody'))['read_marks']);

        $data->erase($ana);
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(ChatSavedMessage::class)->findAll(), 'her rows and the rows of her ticket');
        self::assertSame([], $this->marksOf($ana));
        self::assertSame([], $this->marksOf($agent), 'the read times of her ticket went with it');
        self::assertNotSame([], $this->marksOf($luis), 'Luis keeps his own');
        self::assertCount(1, $this->em->getRepository(ChatConversation::class)->findAll());
    }

    /**
     * @return list<ChatReadMark>
     */
    private function marksOf(TestUser $user): array
    {
        /** @var list<ChatReadMark> $marks */
        $marks = $this->em->createQueryBuilder()->select('r')->from(ChatReadMark::class, 'r')->innerJoin('r.participant', 'p')
            ->where('IDENTITY(p.user) = :u')->setParameter('u', $user->getId())->getQuery()->getResult();

        return $marks;
    }

    /**
     * @template T of ChatConversation|ChatMessage|TestUser
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(ChatConversation|ChatMessage|TestUser $entity): ChatConversation|ChatMessage|TestUser
    {
        $managed = $this->em->find($entity::class, $entity->getId());
        self::assertNotNull($managed);

        return $managed;
    }
}
