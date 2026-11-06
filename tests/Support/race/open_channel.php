<?php

declare(strict_types=1);

/*
 * One process of ChatChannelRaceTest, on the real database of CHAT_KIT_RACE_DATABASE_URL. That has to be a throwaway
 * database: `setup` drops everything in it. A database whose name has no `test`, `race` or `throwaway` part is refused.
 *
 *     open_channel.php setup
 *     open_channel.php reset
 *     open_channel.php count
 *     open_channel.php open <email> <channel key> <none|sleep|locked|write|pending|outer> <start timestamp>
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

$database = ltrim((string) parse_url((string) TestKernel::databaseUrl(), \PHP_URL_PATH), '/');
if (preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', $database) !== 1) {
    throw new RuntimeException(sprintf('Refusing to use database "%s": setup drops everything in it.', $database));
}

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
    $connection->executeStatement('DROP TABLE IF EXISTS race_log');
    $connection->executeStatement('CREATE TABLE race_log (note VARCHAR(40))');
    foreach (['Ana', 'Bob'] as $name) {
        $em->persist(new TestUser($name, strtolower($name) . '@example.test'));
    }
    $em->flush();

    return;
}
if ($command === 'reset') {
    $connection->executeStatement('DELETE FROM nowo_chat_participant');
    $connection->executeStatement('DELETE FROM nowo_chat_conversation');
    $connection->executeStatement('DELETE FROM race_log');

    return;
}
if ($command === 'count') {
    echo (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . ($argv[2] ?? 'nowo_chat_conversation'));

    return;
}

[, , $email, $key, $mode, $start] = $argv;
$limit                            = 1;
$dispatcher                       = $container->get('event_dispatcher');
assert($dispatcher instanceof EventDispatcherInterface);
$dispatcher->addListener(ChatChannelOpeningEvent::class, static function (ChatChannelOpeningEvent $event) use ($connection, $em, $mode, $limit, $email): void {
    if ($mode === 'none') {
        return;
    }
    if ($mode === 'write') {
        $connection->executeStatement('INSERT INTO race_log (note) VALUES (?)', [$email]);
    }
    if ($mode === 'pending') {
        $em->persist(new TestUser('Pending', uniqid('pending-', true) . '@example.test'));
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

$outer = $mode === 'outer';
if ($outer) {
    $connection->beginTransaction();
}
$answer = ['result' => 'opened', 'uuid' => null];

try {
    $answer['uuid'] = $chat->openChannel($user, new ChatChannel($key, 'Race'))->getUuid();
} catch (ChatChannelDeniedException) {
    $answer['result'] = 'denied';
} catch (Throwable $thrown) {
    $answer['result'] = $thrown::class;
}
$committed = true;
if ($outer) {
    try {
        $connection->commit();
    } catch (Throwable) {
        $committed = false;
    }
}

echo json_encode($answer + ['emOpen' => $em->isOpen(), 'committed' => $committed], \JSON_THROW_ON_ERROR);
