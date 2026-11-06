<?php

declare(strict_types=1);

/*
 * One process of ChatChannelRaceTest, on the real database of CHAT_KIT_RACE_DATABASE_URL.
 *
 *     open_channel.php setup
 *     open_channel.php reset
 *     open_channel.php count
 *     open_channel.php open <email> <channel key> <none|sleep|locked> <start timestamp>
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatChannelDeniedException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = new TestKernel();
$kernel->boot();
$container = $kernel->getContainer();
$em        = $container->get(TestKernel::ENTITY_MANAGER_ALIAS);
assert($em instanceof EntityManagerInterface);
$connection = $em->getConnection();
$command    = $argv[1] ?? '';

if ($command === 'setup') {
    $tool = new SchemaTool($em);
    $tool->dropDatabase();
    $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    $connection->executeStatement('DROP TABLE IF EXISTS race_plan');
    $connection->executeStatement('CREATE TABLE race_plan (id INT PRIMARY KEY)');
    $connection->executeStatement('INSERT INTO race_plan (id) VALUES (1)');
    foreach (['Ana', 'Bob'] as $name) {
        $em->persist(new TestUser($name, strtolower($name) . '@example.test'));
    }
    $em->flush();

    return;
}
if ($command === 'reset') {
    $connection->executeStatement('DELETE FROM nowo_chat_participant');
    $connection->executeStatement('DELETE FROM nowo_chat_conversation');

    return;
}
if ($command === 'count') {
    echo (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation');

    return;
}

[, , $email, $key, $mode, $start] = $argv;
$limit                            = 1;
$dispatcher                       = $container->get('event_dispatcher');
assert($dispatcher instanceof EventDispatcherInterface);
$dispatcher->addListener(ChatChannelOpeningEvent::class, static function (ChatChannelOpeningEvent $event) use ($connection, $mode, $limit): void {
    if ($mode === 'none') {
        return;
    }
    if ($mode === 'locked') {
        $connection->fetchOne('SELECT id FROM race_plan WHERE id = 1 FOR UPDATE');
    }
    $used = (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation');
    usleep(150_000);
    if ($mode === 'locked' && $used >= $limit) {
        $event->deny('limit');
    }
});

$services = $container->get('test.service_container');
assert($services instanceof ContainerInterface);
$chat = $services->get(ChatService::class);
assert($chat instanceof ChatService);
$user = $em->getRepository(TestUser::class)->findOneBy(['email' => $email]);
assert($user instanceof TestUser);
time_sleep_until((float) $start);

try {
    $conversation = $chat->openChannel($user, new ChatChannel($key, 'Race'));
    echo json_encode(['result' => 'opened', 'uuid' => $conversation->getUuid(), 'emOpen' => $em->isOpen()], \JSON_THROW_ON_ERROR);
} catch (ChatChannelDeniedException) {
    echo json_encode(['result' => 'denied', 'uuid' => null, 'emOpen' => $em->isOpen()], \JSON_THROW_ON_ERROR);
} catch (Throwable $thrown) {
    echo json_encode(['result' => $thrown::class, 'uuid' => null, 'emOpen' => $em->isOpen()], \JSON_THROW_ON_ERROR);
}
