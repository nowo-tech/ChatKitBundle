<?php

declare(strict_types=1);

/*
 * One of two concurrent writers of tests/Database/ConcurrentWritersTest (ported from audit QD's stress run): each call is
 * one request of a FrankenPHP worker with kernel reset off; after it, what the next request would find is recorded (a
 * transaction left open, a closed manager), then the request boundary runs.
 *
 *   concurrent-writer.php <send|mixed|menu> <calls> <tag> <start timestamp>
 *
 * 1.35: `menu` saves, unsaves, marks unread from and reads the newest message (message info on: read times too).
 *
 * Prints one JSON line: {ok, failures, poisoned, closed}.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $mode, $calls, $tag, $start] = $argv + [null, 'send', '50', 'a', '0'];
$kernel                         = new TestKernel($mode === 'menu' ? ['profiles' => ['default' => ['message_menu' => ['save' => ['enabled' => true], 'message_info' => ['enabled' => true]]]]] : []);
$kernel->boot();
$container = $kernel->getContainer();
$registry  = $container->get('doctrine');
assert($registry instanceof ManagerRegistry);
$chat = $container->get(ChatService::class);
assert($chat instanceof ChatService);
$menu = $container->get(ChatMessageActions::class);
assert($menu instanceof ChatMessageActions);
$boundary = $container->get(ChatRequestBoundaryListener::class);
assert($boundary instanceof ChatRequestBoundaryListener);
$em = static function () use ($registry): EntityManagerInterface {
    $manager = $registry->getManager();
    assert($manager instanceof EntityManagerInterface);

    return $manager;
};
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
        default => 'send',
    };
    try {
        $ana  = $em()->getRepository(TestUser::class)->findOneBy(['name' => 'ana']) ?? throw new RuntimeException('ana');
        $luis = $em()->getRepository(TestUser::class)->findOneBy(['name' => 'luis']) ?? throw new RuntimeException('luis');
        $dm   = $em()->getRepository(ChatConversation::class)->findOneBy(['kind' => 'direct']) ?? throw new RuntimeException('dm');
        $last = static fn (): ChatMessage => $em()->getRepository(ChatMessage::class)->findOneBy(['conversation' => $dm], ['id' => 'DESC']) ?? throw new RuntimeException('message');
        match ($op) {
            'send'    => $chat->send($tag === 'a' ? $ana : $luis, $dm, $tag . ' ' . $i . ' @[ana](' . $ana->getChatPublicId() . ')'),
            'react'   => $chat->toggleReaction($luis, $last(), '👍'),
            'read'    => $chat->markRead($luis, $dm, (int) $last()->getId()),
            'archive' => $chat->archive($luis, [$dm], $i % 8 < 4),
            'save'    => $menu->setSaved($luis, $last(), true),
            'unsave'  => $menu->setSaved($luis, $last(), false),
            'unread'  => $menu->markUnreadFrom($luis, $last()),
        };
        ++$ok;
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
