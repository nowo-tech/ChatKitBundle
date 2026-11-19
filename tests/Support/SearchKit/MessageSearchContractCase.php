<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\SearchKit;

use LogicException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchDefinition;
use Nowo\ChatKitBundle\SearchKit\ChatSearchKitViewer;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 1.38: the SearchKit contract (EntitySearchProviderTestCase) of the "Messages" group on SearchKit's provider base. A
 * tenant is a viewer (ChatKit has no tenants); a seeded row is a message of a direct conversation of the viewer.
 */
abstract class MessageSearchContractCase extends EntitySearchProviderTestCase
{
    use SearchKitContractKernel;

    abstract protected function realDatabase(): bool;

    protected function setUp(): void
    {
        $this->bootContractKernel($this->realDatabase());
    }

    protected function tearDown(): void
    {
        $this->shutdownContractKernel();
    }

    protected function definition(): ChatMessageSearchDefinition
    {
        return $this->definitionWith(20);
    }

    protected function queryBudget(): int
    {
        // The search query, the messages, the other people of their conversations (one directory call).
        return 3;
    }

    protected function sensitiveTitles(): bool
    {
        return true;
    }

    protected function seed(int|string $tenant, string $text): int
    {
        $chat = $this->service(ChatService::class);

        return (int) $chat->send($peer = $this->person('Peer'), $chat->openDirect($this->viewerOf($tenant), $peer), 'note ' . $text)->getId();
    }

    protected function seedHidden(int|string $tenant, string $text): int
    {
        // A participant row that grants nothing: the conversation of two other people (the per-row check hides it).
        $chat         = $this->service(ChatService::class);
        $peer         = $this->person('Peer');
        $conversation = $chat->openDirect($peer, $this->person('Someone'));
        $message      = $chat->send($peer, $conversation, 'note ' . $text);
        $this->em->persist(new ChatParticipant($conversation, $this->viewerOf($tenant)));
        $this->em->flush();

        return (int) $message->getId();
    }

    protected function seedArchived(int|string $tenant, string $text): int
    {
        // A deleted message (the SQL leaves it out).
        $chat    = $this->service(ChatService::class);
        $peer    = $this->person('Peer');
        $message = $chat->send($peer, $chat->openDirect($this->viewerOf($tenant), $peer), 'note ' . $text);
        $chat->delete($peer, $message);

        return (int) $message->getId();
    }

    protected function reference(int|string $id): SearchReference
    {
        $message = $this->em->find(ChatMessage::class, $id);
        self::assertInstanceOf(ChatMessage::class, $message);
        $uuid = $message->getConversation()->getUuid();

        return new SearchReference(
            $this->service(UrlGeneratorInterface::class)->generate(ChatMessageSearchDefinition::ROUTE, ['uuid' => $uuid, 'message' => $message->getUuid()]),
            ChatMessageSearchDefinition::KIND,
            ChatMessageSearchDefinition::ROUTE,
            ['uuid' => $uuid],
        );
    }

    /**
     * The kit's wildcard check, with message search's one difference: its text has no `_` (1.37: the viewer-neutral
     * search text and the text a reader sees both drop the Markdown markers `*`, `_`, `~` and `` ` ``), so a word with
     * `_` finds nothing — and above all never acts as a LIKE wildcard (which would find all five rows). Each query
     * leads with the plain marker: on full-text platforms a query of punctuated words only answers no rows (audit VA-7).
     */
    public function testContractEscapesLikeWildcards(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        foreach (['%x', '_y', '!z', '\\q', 'abc'] as $suffix) {
            $this->seed($tenant, $marker . $suffix);
        }
        $this->actAs($tenant, self::VIEWER_A);

        self::assertCount(5, $this->provider()->search($this->query($marker))->items ?? []);
        foreach (['%', '!', '\\'] as $wildcard) {
            self::assertCount(1, $this->provider()->search($this->query($marker . ' ' . $marker . $wildcard))->items ?? [], '"' . $wildcard . '" must be matched literally.');
        }
        self::assertNull($this->provider()->search($this->query($marker . ' ' . $marker . '_')), '"_" is not in the search text, and never a wildcard.');
        self::assertCount(1, $this->provider()->search($this->query($marker . 'y'))->items ?? [], 'the row of "_y" is found without the marker.');
        self::assertNull($this->provider()->search($this->query($marker . ' ' . $marker . '%%')));
    }

    public function testTheHooksRefuseWhatTheyCannotCheck(): void
    {
        [$a, $b] = $this->tenants();
        $marker  = $this->marker();
        $id      = $this->seed($a, $marker);
        $this->actAs($a, self::VIEWER_A);
        $definition = $this->definition();
        $query      = $this->query($marker);

        self::assertSame('messages', $definition->group());
        self::assertSame('chat_message', $definition->subjectType());
        self::assertSame(['m.search_text'], $definition->searchableFields());
        self::assertSame([], $definition->filterVisible([['id' => $id]], $b, $query), 'another tenant than the signed-in viewer');
        self::assertSame([], $definition->filterVisible([['id' => $id]], $a, $this->query('x')), 'a text below search.min_query_length');
        self::assertSame([['id' => $id]], $definition->filterVisible([['id' => $id]], $a, $query));
        self::assertFalse($definition->baseQuery($b, $query)->executeQuery()->fetchAssociative(), 'refused: a query that matches nothing');
        // The author filter (applied in SQL): an unknown author gives no rows, `me` only the viewer's own.
        self::assertNull($this->provider()->search(new SearchQuery($marker, null, 5, null, false, self::VIEWER_A, [new SearchFilter('from', 'nobody')], null)));
        self::assertNull($this->provider()->search(new SearchQuery($marker, null, 5, null, false, self::VIEWER_A, [new SearchFilter('from', 'me')], null)));
        self::assertNotNull($this->provider()->search(new SearchQuery($marker, null, 5, null, false, self::VIEWER_A, [], null)));
        // The service's own refusals: an unreachable viewer reads nothing; an id no check loaded is no row.
        $search = $this->service(ChatMessageSearch::class);
        self::assertSame([], $search->hitsOf($this->viewerOf($a), [999_999_999], []));
        $this->disableFeature();
        self::assertFalse($search->readableQuery($this->viewerOf($a))->executeQuery()->fetchAssociative());
        $this->actAs(null, self::VIEWER_A);
        $this->expectException(LogicException::class);
        $definition->toItem(['id' => $id], $query);
    }

    public function testRowsWithoutAnIdAreRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->definition()->subjectId(['uuid' => 'x']);
    }

    public function testReferencesAreShapeChecked(): void
    {
        $definition = $this->definition();
        $uuid       = '0198c2e4-5b1a-7c3d-8e9f-0a1b2c3d4e5f';
        $url        = '/chat/' . $uuid . '?message=' . $uuid;
        self::assertSame($uuid, $definition->referenceId(new SearchReference($url, ChatMessageSearchDefinition::KIND, ChatMessageSearchDefinition::ROUTE, ['uuid' => $uuid])));
        foreach ([
            new SearchReference($url, 'chat_conversation', ChatMessageSearchDefinition::ROUTE, ['uuid' => $uuid]),
            new SearchReference($url, ChatMessageSearchDefinition::KIND, 'other', ['uuid' => $uuid]),
            new SearchReference('/chat/' . $uuid . '?message=x', ChatMessageSearchDefinition::KIND, ChatMessageSearchDefinition::ROUTE, ['uuid' => $uuid]),
            new SearchReference($url, ChatMessageSearchDefinition::KIND, ChatMessageSearchDefinition::ROUTE, ['uuid' => 'x']),
            new SearchReference($url, ChatMessageSearchDefinition::KIND, ChatMessageSearchDefinition::ROUTE, []),
        ] as $reference) {
            self::assertNull($definition->referenceId($reference));
        }
        self::assertSame('m.uuid', $definition->idField());
        self::assertSame(1, $this->definitionWith(0)->maxCandidates());
        self::assertSame(1000, $this->definitionWith(5000)->maxCandidates());
    }

    private function definitionWith(int $maxCandidates): ChatMessageSearchDefinition
    {
        $limiter = $this->kernel->getContainer()->get('limiter.nowo_chat_search');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return new ChatMessageSearchDefinition(
            $this->service(ChatMessageSearch::class),
            new ChatSearchKitViewer($this->service(Security::class), $this->service(ChatAccess::class)),
            $this->service(UrlGeneratorInterface::class),
            $this->service(TranslatorInterface::class),
            $limiter,
            $maxCandidates,
        );
    }
}
