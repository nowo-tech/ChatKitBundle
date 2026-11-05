<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Repository\ChatMessageLinkRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function max;
use function min;
use function sprintf;

/**
 * 1.19: fills the link index of the "Links" panel for the messages written before 1.19 (or again after `links.providers`
 * changed: the provider of each row is stored when it is indexed). Walks the messages that may hold a link by id, in
 * batches, and re-indexes each one (its earlier rows are replaced), so it is safe to run again; `--after-id` resumes.
 */
#[AsCommand(name: 'nowo:chat:links:backfill', description: 'Index the links of existing chat messages for the Links panel')]
final class ChatLinksBackfillCommand extends Command
{
    /** Largest `--batch` (one batch stays bounded in memory). */
    public const int MAX_BATCH = 5000;

    /**
     * Builds the command.
     *
     * @param ChatLinks $links Indexes one message
     * @param ChatMessageLinkRepository $index Finds the candidate messages
     * @param EntityManagerInterface $entityManager Loads the messages, flushes and clears each batch
     */
    public function __construct(
        private readonly ChatLinks $links,
        private readonly ChatMessageLinkRepository $index,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Messages per batch (at most 5000)', '500')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after this message id (resume)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->links->isEnabled()) {
            $io->warning('Links are disabled (nowo_chat_kit links.enabled: false): nothing to index.');

            return Command::SUCCESS;
        }
        $batch    = min(self::MAX_BATCH, max(1, (int) $input->getOption('batch')));
        $afterId  = max(0, (int) $input->getOption('after-id'));
        $messages = 0;
        $indexed  = 0;
        do {
            $ids = $this->index->candidateMessageIds($afterId, $batch);
            foreach ($this->entityManager->getRepository(ChatMessage::class)->findBy(['id' => $ids], ['id' => 'ASC']) as $message) {
                $indexed += $this->links->index($message);
                ++$messages;
                $afterId = (int) $message->getId();
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
        } while (count($ids) === $batch);
        $io->success(sprintf('Indexed %d link(s) of %d message(s); last message id %d.', $indexed, $messages, $afterId));

        return Command::SUCCESS;
    }
}
