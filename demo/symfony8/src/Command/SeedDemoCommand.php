<?php

declare(strict_types=1);

namespace App\Command;

use App\Chat\DemoChannelProvider;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Demo accounts (alice, bob, carol, support — password "password") and a few starter conversations.
 * Idempotent: existing users and non-empty conversations are left alone.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: 'app:demo:seed', description: 'Create the demo accounts and starter conversations')]
final class SeedDemoCommand extends Command
{
    private const array USERS = [
        ['alice', 'Alice Martin', []],
        ['bob', 'Bob Lee', []],
        ['carol', 'Carol Diaz', []],
        ['support', 'Sam Support', ['ROLE_SUPPORT']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly ChatService $chat,
        private readonly ChatMessageRepository $messages,
        private readonly DemoChannelProvider $channels,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $accounts = [];
        foreach (self::USERS as [$username, $name, $roles]) {
            $accounts[$username] = $this->users->findOneBy(['username' => $username]) ?? new User($username, $name, $roles);
            $this->entityManager->persist($accounts[$username]);
        }
        $this->entityManager->flush();

        $dm = $this->chat->openDirect($accounts['alice'], $accounts['bob']);
        if (null === $this->messages->findLatest($dm)) {
            $this->chat->send($accounts['bob'], $dm, 'Hi Alice! Did you see the new **chat dock**?');
            $this->chat->send($accounts['alice'], $dm, "Yes! It supports:\n- direct messages\n- channels\n- a support inbox");
        }
        $general = $this->chat->openChannel($accounts['carol'], $this->channels->find(DemoChannelProvider::KEY) ?? throw new \LogicException('No channel.'));
        if (null === $this->messages->findLatest($general)) {
            $this->chat->send($accounts['carol'], $general, 'Welcome to the General channel 👋');
        }
        $support = $this->chat->openSupport($accounts['carol']);
        if (null === $this->messages->findLatest($support)) {
            $this->chat->send($accounts['carol'], $support, 'How do I change my notification sound?');
        }

        $io->success('Demo ready: alice, bob, carol and support (password "password").');

        return Command::SUCCESS;
    }
}
