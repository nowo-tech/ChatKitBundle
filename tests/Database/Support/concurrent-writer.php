<?php

declare(strict_types=1);

/*
 * One of two concurrent writers of tests/Database/ConcurrentWritersTest (ported from audit QD's stress run): each call is
 * one request of a FrankenPHP worker with kernel reset off; after it, what the next request would find is recorded (a
 * transaction left open, a closed manager), then the request boundary runs.
 *
 *   concurrent-writer.php <send|mixed|menu> <calls> <tag> <start timestamp>
 *
 * 1.35: `menu` saves, unsaves, marks unread from and reads the newest message (message info on: read times too);
 * `savecap` saves the first message under `max_per_user: 1`; `unread` marks unread and reads in turn; `readonly` reads;
 * `optout` turns "Share read times" off and on (ending off).
 *
 * 1.36: `forward` forwards the first message into the conversation under one shared `Idempotency-Key` (one effect for
 * both workers); `thread` replies in the first message's thread (a key per call); `edit` edits the first message (edit
 * history on); `delreply` deletes the first message's replies one by one (both workers the same ones); `delroot`
 * deletes the first message halfway through.
 *
 * 2.0: `purge` deletes the seeded channel conversations `room:race-<n>` one by one through
 * ChatMessagingInterface::purgeChannel() (both workers the same ones, at the same moment: one deletes, the other finds
 * nothing; neither throws).
 *
 * Prints one JSON line: {ok, failures, poisoned, closed}.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatForwarder;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatMessaging;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $mode, $calls, $tag, $start] = $argv + [null, 'send', '50', 'a', '0'];
$warm                           = str_starts_with((string) $mode, 'warm-');
$mode                           = $warm ? substr((string) $mode, 5) : $mode;
$menuModes                      = ['menu', 'savecap', 'unread', 'readonly', 'optout'];
$threadModes                    = ['forward', 'thread', 'edit', 'delreply', 'delroot'];
$kernel                         = new TestKernel(match (true) {
    in_array($mode, $menuModes, true)   => ['profiles' => ['default' => ['message_menu' => ['save' => ['enabled' => true, 'max_per_user' => $mode === 'savecap' ? 1 : 500], 'message_info' => ['enabled' => true]]]]],
    in_array($mode, $threadModes, true) => ['profiles' => ['default' => ['message_menu' => ['forward' => ['enabled' => true], 'threads' => ['enabled' => true], 'edit_history' => ['enabled' => true, 'max_revisions' => 200]]]]],
    default                             => [],
});
$kernel->boot();
$container = $kernel->getContainer();
$registry  = $container->get('doctrine');
assert($registry instanceof ManagerRegistry);
$chat = $container->get(ChatService::class);
assert($chat instanceof ChatService);
$menu = $container->get(ChatMessageActions::class);
assert($menu instanceof ChatMessageActions);
$forwarder = $container->get(ChatForwarder::class);
assert($forwarder instanceof ChatForwarder);
$threads = $container->get(ChatThreads::class);
assert($threads instanceof ChatThreads);
$settings = $container->get(ChatUserSettingsRepository::class);
assert($settings instanceof ChatUserSettingsRepository);
$boundary = $container->get(ChatRequestBoundaryListener::class);
assert($boundary instanceof ChatRequestBoundaryListener);
$messaging = $container->get(ChatMessaging::class);
assert($messaging instanceof ChatMessaging);
$em = static function () use ($registry): EntityManagerInterface {
    $manager = $registry->getManager();
    assert($manager instanceof EntityManagerInterface);

    return $manager;
};
// YB-2: `warm-<mode>` boots this kernel config and writes every Doctrine proxy class to the shared cache, so the racing writers
// never generate proxies concurrently on a cold cache (a half-written proxy file fails the other process's require).
if ($warm) {
    foreach ($registry->getManagers() as $manager) {
        assert($manager instanceof EntityManagerInterface);
        $manager->getProxyFactory()->generateProxyClasses($manager->getMetadataFactory()->getAllMetadata());
    }
    echo json_encode(['ok' => 0, 'failures' => [], 'poisoned' => 0, 'closed' => 0]);

    return;
}
while (microtime(true) < (float) $start) {
    usleep(1000);
}

$ok       = 0;
$failures = [];
$poisoned = 0;
$closed   = 0;
for ($i = 0; $i < (int) $calls; ++$i) {
    $op = match ($mode) {
        'mixed' => ['send', 'react', 'read', 'archive'][$i % 4],
        'menu'  => ['save', 'unread', 'read', 'unsave'][$i % 4],
        // 1.35 (audit TK I3): saves at the cap, unread against read (TK-7), opt-out against read (TK-4).
        'savecap'  => 'savecap',
        'unread'   => $i % 2 === 0 ? 'unread' : 'read',
        'readonly' => 'read',
        'optout'   => 'optout',
        'forward'  => 'forward',
        'thread'   => 'thread',
        'edit'     => 'edit',
        // 1.36 (audit UB-4): both workers delete the same replies; one deletes the root while the other replies.
        'delreply' => 'delreply',
        'delroot'  => $i === intdiv((int) $calls, 2) ? 'delroot' : 'idle',
        // 2.0: both workers purge the same channel conversations in the same order.
        'purge' => 'purge',
        default => 'send',
    };
    try {
        $ana   = $em()->getRepository(TestUser::class)->findOneBy(['name' => 'ana']) ?? throw new RuntimeException('ana');
        $luis  = $em()->getRepository(TestUser::class)->findOneBy(['name' => 'luis']) ?? throw new RuntimeException('luis');
        $dm    = $em()->getRepository(ChatConversation::class)->findOneBy(['kind' => 'direct']) ?? throw new RuntimeException('dm');
        $last  = static fn (): ChatMessage => $em()->getRepository(ChatMessage::class)->findOneBy(['conversation' => $dm], ['id' => 'DESC']) ?? throw new RuntimeException('message');
        $first = static fn (): ChatMessage => $em()->getRepository(ChatMessage::class)->findOneBy(['conversation' => $dm], ['id' => 'ASC']) ?? throw new RuntimeException('first');
        match ($op) {
            'send'    => $chat->send($tag === 'a' ? $ana : $luis, $dm, $tag . ' ' . $i . ' @[ana](' . $ana->getChatPublicId() . ')'),
            'react'   => $chat->toggleReaction($luis, $last(), '👍'),
            'read'    => $chat->markRead($luis, $dm, (int) $last()->getId()),
            'archive' => $chat->archive($luis, [$dm], $i % 8 < 4),
            'save'    => $menu->markSaved($luis, $last(), true),
            'unsave'  => $menu->markSaved($luis, $last(), false),
            'unread'  => $menu->markUnreadFrom($luis, $last()),
            // Each call another of the first messages; over the cap the service refuses (a soft cap, docs/CONCURRENCY.md).
            'savecap'  => $menu->markSaved($luis, $em()->getRepository(ChatMessage::class)->findOneBy(['conversation' => $dm], ['id' => 'ASC']) ?? throw new RuntimeException('first'), true),
            'forward'  => $forwarder->forward($luis, $first(), [['conversationUuid' => $dm->getUuid()]], null, 'one-forward'),
            'thread'   => $threads->reply($tag === 'a' ? $ana : $luis, $first(), $tag . ' reply ' . $i, $tag . '-' . $i),
            'edit'     => $chat->edit($first(), $tag . ' edit ' . $i),
            'delreply' => $chat->delete($ana, $em()->getRepository(ChatMessage::class)->findBy(['threadRoot' => $first()], ['id' => 'ASC'])[$i] ?? throw new RuntimeException('reply')),
            'delroot'  => $chat->delete($ana, $first()),
            'idle'     => usleep(2000),
            // True for the worker that deleted it, false for the one that found it gone: both are success.
            'purge'  => $messaging->purgeChannel('room:race-' . $i),
            'optout' => (static function () use ($settings, $luis, $i): void {
                $mine = $settings->forUser($luis);
                $mine->setShareReadTimes($i % 2 === 0);
                $settings->save($mine);
            })(),
        };
        ++$ok;
    } catch (InvalidArgumentException $refused) {
        // `too_many_saved`: the cap answered, as it should.
        // 1.36: `not_threadable`: a reply to a root deleted meanwhile is refused, as it should.
        in_array($refused->getMessage(), ['too_many_saved', 'not_threadable'], true) ? ++$ok : $failures[$op . ': ' . $refused->getMessage()] = ($failures[$op . ': ' . $refused->getMessage()] ?? 0) + 1;
    } catch (Throwable $failure) {
        $failures[$op . ': ' . $failure::class] = ($failures[$op . ': ' . $failure::class] ?? 0) + 1;
    }
    // What the next request of this worker finds.
    if ($em()->getConnection()->getTransactionNestingLevel() > 0) {
        ++$poisoned;
    }
    $boundary->clear();
    if (!$em()->isOpen()) {
        ++$closed;
        $registry->resetManager();
    }
    $em()->clear();
}

echo json_encode(['ok' => $ok, 'failures' => $failures, 'poisoned' => $poisoned, 'closed' => $closed], \JSON_THROW_ON_ERROR), "\n";
