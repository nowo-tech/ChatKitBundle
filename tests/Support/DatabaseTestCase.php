<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

use function assert;
use function preg_replace;

/**
 * Boots the test kernel on in-memory SQLite with the full schema (bundle entities + test user).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected TestKernel $kernel;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel($this->chatKitConfig(), false, $this->sidebarProviders(), $this->grantReferences(), $this->twinPorts(), $this->authorBadges(), $this->hostTransactionRunner(), $this->runtimeSettings(), $this->uiKitContextMenus(), $this->hostFixtures(), null, $this->cardProviders(), $this->attachments());
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $cache = $this->kernel->getContainer()->get('cache.app');
        assert($cache instanceof CacheItemPoolInterface);
        $cache->clear();
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatKitConfig(): array
    {
        return [];
    }

    /**
     * 1.25: whether the kernel registers the test sidebar section providers.
     */
    protected function sidebarProviders(): bool
    {
        return false;
    }

    /**
     * 1.27: whether the kernel wires the host ports through their framework-free twins only (tests/Support/TwinPorts).
     */
    protected function twinPorts(): bool
    {
        return false;
    }

    /**
     * 1.30: whether the kernel binds TransactionRunnerInterface to HostEntityManagerRunner (a host runner ChatKit must ignore).
     */
    protected function hostTransactionRunner(): bool
    {
        return false;
    }

    /**
     * 1.33: whether the kernel turns on UiKit's `theme.context_menus` (the message kebab follows it by default).
     */
    protected function uiKitContextMenus(): bool
    {
        return false;
    }

    /**
     * 2.0: whether the fixtures (`$this->em`) are written through the application's manager instead of ChatKit's private
     * one (chat rows loaded by the host manager handed to the chat services).
     */
    protected function hostFixtures(): bool
    {
        return false;
    }

    /**
     * 1.32: whether the kernel registers nowo-tech/runtime-setting-contract's bridge over its in-memory store.
     */
    protected function runtimeSettings(): bool
    {
        return false;
    }

    /**
     * 1.29: whether the kernel aliases ChatAuthorBadgeProviderInterface to TestAuthorBadgeProvider.
     */
    protected function authorBadges(): bool
    {
        return false;
    }

    /**
     * 2.6 (spec 259): whether the kernel registers the test card, composer action and message menu slot providers.
     */
    protected function cardProviders(): bool
    {
        return false;
    }

    /**
     * 2.7 (spec 259): whether the kernel registers StorageKit's ports in memory, the test scanner and the in-memory
     * Messenger transport (the test's chatKitConfig() turns attachments on).
     */
    protected function attachments(): bool
    {
        return false;
    }

    /**
     * 1.26.3: whether the kernel registers the `/doc` provider whose grants live in a table (worker tests).
     */
    protected function grantReferences(): bool
    {
        return false;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function get(string $id): object
    {
        $container = $this->kernel->getContainer();
        if (!$container->has($id)) {
            $container = $container->get('test.service_container');
            assert($container instanceof ContainerInterface);
        }
        $service = $container->get($id);
        assert($service instanceof $id);

        return $service;
    }

    protected function twig(): Environment
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $twig = $container->get('twig');
        assert($twig instanceof Environment);

        return $twig;
    }

    /**
     * Renders the dock `<template>` markup inside a request with a session (the dock forms carry the `nowo_chat`
     * CSRF token, as on a signed-in page) and replaces the random token values with a fixed placeholder.
     */
    protected function renderDockTemplates(): string
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->get(RequestStack::class)->push($request);

        $html = $this->twig()->render('@NowoChatKitBundle/_templates.html.twig');

        return (string) preg_replace('/(name="[a-z_]+\[_token\]"[^>]*value=")[^"]*"/', '$1csrf-token"', $html);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /**
     * 2.0: the application's own entity manager (the test acting as the host; the chat writes through its private one).
     */
    protected function hostManager(): EntityManagerInterface
    {
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /**
     * 2.0: the ids of chat users, to compare users across managers (chat rows hold the private manager's instances).
     *
     * @param iterable<ChatUserInterface> $users
     *
     * @return list<int|string|null>
     */
    protected static function userIds(iterable $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $ids[] = $user->getId();
        }

        return $ids;
    }

    protected function user(string $name): TestUser
    {
        $user = new TestUser($name, strtolower($name) . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
