<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Form\ChatPreferencesType;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_map;
use function assert;
use function count;
use function json_decode;
use function json_encode;
use function strip_tags;

use const JSON_THROW_ON_ERROR;

/**
 * 1.35, audit TK regressions: message info in support tickets lists only the agents who took part (TK-1); turning
 * "Share read times" off deletes the stored times on every path, and times from before a re-enable stay hidden
 * (TK-2 / TK-4); compaction keeps an early read time (TK-3); the Saved list pages past a run of hidden rows (TK-6).
 */
final class ChatMessageMenuAuditTkTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => [
            'save'         => ['enabled' => true, 'max_per_user' => 1000],
            'message_info' => ['enabled' => true],
        ]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testSupportInfoListsOnlyTheAgentsWhoTookPart(): void
    {
        $cora  = $this->user('Cora');
        $alpha = $this->user('Agent Alpha');
        $this->user('Agent Bravo');
        $chat   = $this->get(ChatService::class);
        $ticket = $chat->openSupport($cora);
        $mine   = $chat->send($this->fresh($cora), $this->fresh($ticket), 'I need help');
        $url    = '/chat/api/conversations/' . $ticket->getUuid() . '/messages/' . $mine->getUuid() . '/info';
        $this->login($cora);
        $this->client->request(Request::METHOD_GET, $url);
        $answer = $this->decode();
        self::assertSame([], $answer['readers'], 'no agent took part yet');
        self::assertSame(0, $answer['memberCount']);

        // Agent Alpha reads the ticket: the customer sees Alpha, never Bravo.
        $chat->markRead($this->fresh($alpha), $this->fresh($ticket), (int) $mine->getId());
        $this->login($cora);
        $this->client->request(Request::METHOD_GET, $url);
        $answer = $this->decode();
        self::assertSame(['Agent Alpha'], array_column($answer['readers'], 'name'));
        self::assertSame(1, $answer['memberCount']);

        // An agent (moderator) sees the whole team.
        $this->login($alpha);
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(2, $this->decode()['memberCount']);
    }

    public function testAFormOptOutDeletesTheReadTimesAndOldTimesStayHidden(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $mine = $chat->send($ana, $dm, 'read me');
        $chat->markRead($this->fresh($luis), $this->fresh($dm), (int) $mine->getId());
        self::assertCount(1, $this->marksOf($luis));

        // The host's preferences page: form submit then save (as ChatPreferencesType's docs say).
        $settingsRepo = $this->get(ChatUserSettingsRepository::class);
        $settingsRepo->reset();
        $settings = $settingsRepo->forUser($this->fresh($luis));
        $form     = $this->get(FormFactoryInterface::class)->create(ChatPreferencesType::class, $settings, ['csrf_protection' => false]);
        $form->submit(['shareReadTimes' => false], false);
        self::assertTrue($form->isSubmitted() && $form->isValid(), (string) $form->getErrors(true));
        $settingsRepo->save($settings);
        self::assertSame([], $this->marksOf($luis), 'deleted in the same flush');
        // Another preference saved while sharing stays off deletes nothing more (nothing to delete, no change of it).
        $settingsRepo->reset();
        $settings = $settingsRepo->forUser($this->fresh($luis));
        $settings->setVolume(40);
        $settingsRepo->save($settings);

        // A read racing the opt-out (TK-4) left a row: it stays hidden once sharing is back on.
        $participant = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $this->fresh($dm), 'user' => $this->fresh($luis)]);
        self::assertInstanceOf(ChatParticipant::class, $participant);
        $this->get(ChatReadMarkRepository::class)->record($participant, (int) $mine->getId(), new DateTimeImmutable('-1 minute'));
        $this->em->flush();
        $settingsRepo->reset();
        $settings = $settingsRepo->forUser($this->fresh($luis));
        $settings->setShareReadTimes(true);
        $settingsRepo->save($settings);
        $settingsRepo->reset();
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $mine->getUuid() . '/info');
        $answer = $this->decode();
        self::assertTrue($answer['readers'][0]['read']);
        self::assertNull($answer['readers'][0]['readAt'], 'a time from before the re-enable');

        // A first save of the settings with sharing off deletes too (the unsaved defaults shared).
        $bea     = $this->user('Bea');
        $withBea = $chat->openDirect($this->fresh($ana), $bea);
        $hers    = $chat->send($this->fresh($ana), $withBea, 'hi Bea');
        $chat->markRead($this->fresh($bea), $this->fresh($withBea), (int) $hers->getId());
        self::assertCount(1, $this->marksOf($bea));
        $settingsRepo->reset();
        $first = $settingsRepo->forUser($this->fresh($bea));
        self::assertNull($first->getId());
        $first->setShareReadTimes(false);
        $settingsRepo->save($first);
        self::assertSame([], $this->marksOf($bea));
    }

    public function testCompactionKeepsAnEarlyReadTime(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $m1   = $chat->send($ana, $dm, 'one');
        $chat->markRead($this->fresh($luis), $this->fresh($dm), (int) $m1->getId());
        $participant = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $this->fresh($dm), 'user' => $this->fresh($luis)]);
        self::assertInstanceOf(ChatParticipant::class, $participant);
        $marks = $this->get(ChatReadMarkRepository::class);
        $this->em->createQuery('DELETE FROM ' . ChatReadMark::class . ' r')->execute();
        $t0 = new DateTimeImmutable('2026-11-18 10:00:00');
        $marks->record($participant, (int) $m1->getId(), $t0);
        $this->em->flush();
        for ($i = 1; $i <= 72; ++$i) {
            $marks->record($participant, (int) $m1->getId() + $i, $t0->modify('+' . (50 * $i) . ' seconds'));
            $this->em->flush();
        }
        $times = $marks->readTimes([(int) $participant->getId()], (int) $m1->getId());
        self::assertLessThanOrEqual('10:00:50', $times[(int) $participant->getId()]->format('H:i:s'));
        self::assertGreaterThan(30, count($this->em->getRepository(ChatReadMark::class)->findAll()), 'a row per minute, not one for the hour');
    }

    public function testTheSavedListPagesPastHiddenRows(): void
    {
        $bea  = $this->user('Bea');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($bea, $luis);
        $old  = $chat->send($luis, $dm, 'visible, saved first');
        $this->em->persist(new ChatSavedMessage($this->fresh($old), $this->fresh($bea), new DateTimeImmutable('-1 day')));
        $this->em->flush();
        $csrf       = $this->login($bea)['csrf'];
        $room       = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        $roomEntity = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $room['uuid']]);
        self::assertInstanceOf(ChatConversation::class, $roomEntity);
        $b = $this->fresh($bea);
        for ($i = 0; $i < 320; ++$i) {
            $m = new ChatMessage($roomEntity, $this->fresh($luis), 'hidden ' . $i, '<p>hidden</p>');
            $this->em->persist($m);
            $this->em->persist(new ChatSavedMessage($m, $b, new DateTimeImmutable()));
        }
        $this->em->flush();
        $this->em->clear();
        $this->rename($bea, 'Stranger Bea');
        $this->client->loginUser($this->fresh($bea));
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        $answer = $this->decode();
        self::assertSame([], $answer['items']);
        self::assertNotNull($answer['next'], 'the scan goes on');
        $this->client->request(Request::METHOD_GET, '/chat/api/saved?before=' . $answer['next']);
        $answer = $this->decode();
        self::assertSame(['visible, saved first'], array_map(static fn (array $item): string => strip_tags($item['message']['html']), $answer['items']));
        self::assertNull($answer['next']);
    }

    /** @return list<ChatReadMark> */
    private function marksOf(TestUser $user): array
    {
        $this->em->clear();

        return $this->em->createQueryBuilder()->select('r')->from(ChatReadMark::class, 'r')->innerJoin('r.participant', 'p')
            ->where('IDENTITY(p.user) = :u')->setParameter('u', $user->getId())->getQuery()->getResult();
    }

    private function rename(TestUser $user, string $name): void
    {
        $meta = $this->em->getClassMetadata(TestUser::class);
        $this->em->getConnection()->update($meta->getTableName(), [$meta->getColumnName('name') => $name], [$meta->getColumnName('id') => $user->getId()]);
        $this->em->clear();
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

    /** @return array<string, mixed> */
    private function login(TestUser $user): array
    {
        $this->em->clear();
        $this->client->loginUser($this->fresh($user));
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $csrf, int $status = 0, array $body = []): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        if ($status !== 0) {
            self::assertSame($status, $this->client->getResponse()->getStatusCode());
        }

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
