<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Search;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatMessageSearchHit;
use Nowo\ChatKitBundle\Search\ChatMessageSearchSql;
use Nowo\ChatKitBundle\Search\ChatSearchSchemaListener;
use Nowo\ChatKitBundle\Search\ChatSearchScope;
use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchProvider;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function implode;

/**
 * 1.37 (spec 002): the edges of message search the HTTP tests do not reach — the per-row re-check, hidden-text runs
 * longer than one page, look-alike authors, an unreadable retention, recents (`find()`) — and the SearchKit provider:
 * only for the signed-in user the query names, deny by default for scopes and unknown filters, paging and recents.
 */
final class ChatMessageSearchServiceTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testOffAnswersNothing'       => [],
            'testProviderSharesTheBudget' => ['profiles' => ['default' => ['search' => ['enabled' => true], 'search_kit' => ['enabled' => true], 'limits' => ['searches_per_minute' => 2]]]],
            default                       => ['profiles' => ['default' => ['search' => ['enabled' => true, 'page_size' => 1], 'search_kit' => ['enabled' => true]]]],
        };
    }

    public function testOffAnswersNothing(): void
    {
        $ana    = $this->user('Ana');
        $search = $this->get(ChatMessageSearch::class);
        self::assertFalse($search->enabled());
        self::assertSame([], $search->search($ana, $search->query('hello'))->hits);
        self::assertNull($search->find($ana, Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()));
        self::assertFalse($this->kernel->getContainer()->has(ChatMessageSearchProvider::class), 'no provider without search_kit.enabled');
        self::assertTrue($this->kernel->getContainer()->has(ChatSearchSchemaListener::class), 'audit VA-4: always registered (it keeps an existing index)');
        // A provider built by hand while search is off answers nothing.
        $provider = $this->provider($search);
        $this->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($ana, 'main', $ana->getRoles()));
        self::assertNull($provider->search(new SearchQuery('hello', null, 5, null, false, $ana->getUserIdentifier())));
        // A scope that may read nothing never queries.
        $sql = $this->get(ChatMessageSearchSql::class);
        self::assertSame([], $sql->candidateIds(new ChatSearchScope(null, false, []), $search->query('hello'), null, new DateTimeImmutable('-1 day'), null, 5));
        self::assertNull($sql->readableId(new ChatSearchScope(null, false, []), Uuid::v4()->toRfc4122()));
    }

    public function testPerRowRecheckAndHiddenTextRuns(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $eve    = $this->user('Eve');
        $chat   = $this->get(ChatService::class);
        $dm     = $chat->openDirect($ana, $luis);
        $search = $this->get(ChatMessageSearch::class);
        $first  = $chat->send($luis, $dm, 'oldest topic note');
        for ($i = 0; $i < 12; ++$i) {
            $hidden = $chat->send($ana, $dm, 'placeholder ' . $i);
            $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.body', ':body')->where('m.id = :id')
                ->setParameter('body', 'topic #[Secret plan](task:2)')->setParameter('id', $hidden->getId())->getQuery()->execute();
        }
        // Luis cannot open the reference: twelve rows match in SQL only. With one row per page and five reads of two
        // rows, the first page is empty but goes on from the last readable row; the pages after it reach the oldest.
        $page = $search->search($luis, $search->query('topic secret'));
        self::assertSame([], $page->hits);
        self::assertNotNull($page->next);
        $found  = [];
        $cursor = null;
        for ($i = 0; $i < 5; ++$i) {
            $page   = $search->search($luis, $search->query('topic', null, null, null, null, false, $cursor));
            $found  = [...$found, ...array_map(static fn (ChatMessageSearchHit $hit): string => $hit->messageUuid, $page->hits)];
            $cursor = $page->next;
        }
        self::assertContains($first->getUuid(), $found);

        // A participant row that does not grant reading (Eve in Ana and Luis's conversation): the SQL lets the rows
        // through, the per-row check (ChatAccess::canView()) drops them.
        $conversation = $this->em->find(ChatConversation::class, $dm->getId());
        self::assertInstanceOf(ChatConversation::class, $conversation);
        $this->em->persist(new ChatParticipant($conversation, $this->fresh($eve)));
        $this->em->flush();
        self::assertSame([], $search->search($this->fresh($eve), $search->query('oldest'))->hits);
        self::assertSame([$first->getUuid()], array_map(static fn (ChatMessageSearchHit $hit): string => $hit->messageUuid, $search->search($this->fresh($ana), $search->query('oldest'))->hits));
        // Unreachable viewers get nothing.
        self::assertSame([], $search->search($this->user('Disabled Dora'), $search->query('oldest'))->hits);
    }

    public function testLookAlikeAuthorsAndUnreadableRetention(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $chat->send($luis, $chat->openDirect($ana, $luis), 'quarterly figures');

        $directory = new class($luis) implements ChatDirectoryInterface {
            public function __construct(private readonly ChatUserInterface $luis)
            {
            }

            public function findByPublicId(string $publicId): ChatUserInterface
            {
                return $this->luis;
            }

            public function findByIds(array $ids): array
            {
                return [];
            }

            public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
            {
                return [];
            }

            public function supportAgents(): array
            {
                return [];
            }
        };
        $logger = new RecordingLogger();
        $make   = fn (ChatDirectoryInterface $directory, int $days): ChatMessageSearch => new ChatMessageSearch(
            $this->get(ChatMessageSearchSql::class),
            $this->get(ChatAccess::class),
            $this->get(ChatChannelProviderInterface::class),
            $directory,
            $this->get(ChatMessageRepository::class),
            $this->get(ChatPresenter::class),
            $this->get(ChatReferences::class),
            $this->get(ChatMarkdownRenderer::class),
            enabled: true,
            retentionDays: $days,
            logger: $logger,
        );
        // The directory folds case: a look-alike public id never resolves to Luis.
        $folding = $make($directory, 365);
        self::assertSame([], $folding->search($ana, $folding->query('quarterly', null, strtoupper($luis->getChatPublicId())))->hits);
        self::assertCount(1, $folding->search($ana, $folding->query('quarterly', null, $luis->getChatPublicId()))->hits);
        // A configured retention of 0 cannot be read: fail closed, logged without the text.
        $broken = $make($directory, 0);
        self::assertSame([], $broken->search($ana, $broken->query('quarterly'))->hits);
        self::assertNull($broken->find($ana, Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()));
        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString('quarterly', implode("\n", $logger->lines));
    }

    public function testFindRechecksEverything(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $eve    = $this->user('Eve');
        $chat   = $this->get(ChatService::class);
        $dm     = $chat->openDirect($ana, $luis);
        $root   = $chat->send($luis, $dm, 'root line');
        $reply  = $chat->send($ana, $dm, 'reply line');
        $old    = $chat->send($ana, $dm, 'old line');
        $gone   = $chat->send($ana, $dm, 'gone line');
        $buzz   = $chat->buzz($ana, $dm);
        $search = $this->get(ChatMessageSearch::class);
        $conv   = $dm->getUuid();
        $chat->delete($ana, $gone);
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.threadRoot', ':root')->where('m.id = :id')
            ->setParameter('root', $root->getId())->setParameter('id', $reply->getId())->getQuery()->execute();

        $hit = $search->find($ana, $conv, $reply->getUuid());
        self::assertInstanceOf(ChatMessageSearchHit::class, $hit);
        self::assertSame($root->getUuid(), $hit->threadRootUuid);
        self::assertSame('reply line', $hit->snippet);
        self::assertSame([['text' => 'reply line', 'mark' => false]], $hit->segments);
        self::assertSame('Luis', $hit->conversationTitle);

        // Not readable, another conversation's uuid, deleted, a buzz, malformed ids, unreachable viewer: all null.
        self::assertNull($search->find($eve, $conv, $reply->getUuid()));
        self::assertNull($search->find($ana, Uuid::v4()->toRfc4122(), $reply->getUuid()));
        self::assertNull($search->find($ana, $conv, $gone->getUuid()));
        self::assertNull($search->find($ana, $conv, $buzz->getUuid()));
        self::assertNull($search->find($ana, 'x', $reply->getUuid()));
        self::assertNull($search->find($ana, $conv, 'x'));
        self::assertNull($search->find($this->user('Disabled Dee'), $conv, $reply->getUuid()));
        // Outside retention, and a reply whose root is outside it.
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.createdAt', ':old')->where('m.id IN (:ids)')
            ->setParameter('old', new DateTimeImmutable('-400 days'))->setParameter('ids', [$old->getId(), $root->getId()])->getQuery()->execute();
        $this->em->clear();
        self::assertNull($search->find($this->fresh($ana), $conv, $old->getUuid()));
        self::assertNull($search->find($this->fresh($ana), $conv, $reply->getUuid()));
    }

    public function testSearchKitProvider(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $his  = $chat->send($luis, $dm, 'roadmap draft from Luis');
        $mine = $chat->send($ana, $dm, 'roadmap reply from Ana');
        self::assertTrue($this->kernel->getContainer()->has(ChatSearchSchemaListener::class), 'registered with search.enabled');
        // Audit VA-3: this kernel has no SearchKit bundle (no revalidated recents): the provider is not registered.
        self::assertFalse($this->kernel->getContainer()->has(ChatMessageSearchProvider::class), 'fail closed without revalidated recents');
        $provider = $this->provider($this->get(ChatMessageSearch::class));
        self::assertSame('messages', $provider->groupKey());
        self::assertSame(['from'], $provider->supportedFilters());

        // No signed-in user, or another user than the query's: nothing.
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap')));
        $this->signIn($ana);
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap', $luis->getUserIdentifier())));

        $group = $provider->search($this->searchQuery($ana, 'roadmap', $ana->getUserIdentifier()));
        self::assertNotNull($group);
        self::assertSame('messages', $group->key);
        self::assertSame('Messages', $group->label);
        self::assertNull($group->total);
        self::assertSame('/chat/search?q=roadmap', $group->moreUrl);
        self::assertSame('All matching messages', $group->moreLabel);
        self::assertCount(2, $group->items, 'the query limit, not page_size');
        $item = $group->items[0];
        self::assertSame('roadmap reply from Ana', $item->title);
        self::assertSame('Luis · Ana', $item->subtitle);
        self::assertSame('/chat/' . $dm->getUuid() . '?message=' . $mine->getUuid(), $item->url);
        self::assertSame(ChatMessageSearchProvider::KIND, $item->kind);
        self::assertNotNull($item->date);

        // from:me and from:<public id>.
        self::assertSame('roadmap reply from Ana', $provider->search($this->searchQuery($ana, 'roadmap', null, [new SearchFilter('from', 'me')]))?->items[0]->title);
        self::assertSame('roadmap draft from Luis', $provider->search($this->searchQuery($ana, 'roadmap', null, [new SearchFilter('from', $luis->getChatPublicId())]))?->items[0]->title);
        // Deny by default: a negated or repeated from:, another filter key, a scope, a too-short text, no match.
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap', null, [new SearchFilter('from', 'me', true)])));
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap', null, [new SearchFilter('from', 'me'), new SearchFilter('from', 'me')])));
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap', null, [new SearchFilter('is', 'open')])));
        self::assertNull($provider->search($this->searchQuery($ana, 'roadmap', null, [], 'project-1')));
        self::assertNull($provider->search($this->searchQuery($ana, 'r')));
        self::assertNull($provider->search($this->searchQuery($ana, 'nothing here')));

        // Paging on the results page.
        self::assertSame('roadmap draft from Luis', $provider->searchPage($this->searchQuery($ana, 'roadmap'), 2, 1)?->items[0]->title);
        self::assertNull($provider->searchPage($this->searchQuery($ana, 'roadmap'), 3, 1));
        self::assertNull($provider->searchPage($this->searchQuery($ana, 'roadmap'), 0, 1));
        self::assertNull($provider->searchPage($this->searchQuery($ana, 'roadmap'), 2, 5), 'page 1 was not full: there is no page 2');
        self::assertNull($provider->searchPage($this->searchQuery($ana, 'roadmap'), ChatMessageSearchProvider::MAX_PAGE + 1, 1));
        self::assertCount(2, $provider->searchPage($this->searchQuery($ana, 'roadmap'), 1, 10)->items ?? []);

        // Recents: re-checked now, same URL only.
        $url       = '/chat/' . $dm->getUuid() . '?message=' . $his->getUuid();
        $reference = static fn (string $url, string $kind = ChatMessageSearchProvider::KIND, string $route = 'nowo_chat_page_conversation', array $parameters = []): SearchReference => new SearchReference($url, $kind, $route, $parameters);
        $resolved  = $provider->resolve($reference($url, parameters: ['uuid' => $dm->getUuid()]), $ana->getUserIdentifier(), 'en');
        self::assertInstanceOf(SearchItem::class, $resolved);
        self::assertSame($url, $resolved->url);
        self::assertSame('roadmap draft from Luis', $resolved->title);
        self::assertNull($provider->resolve($reference($url . '&x=1', parameters: ['uuid' => $dm->getUuid()]), $ana->getUserIdentifier(), 'en'), 'another URL');
        self::assertNull($provider->resolve($reference($url, 'other', parameters: ['uuid' => $dm->getUuid()]), $ana->getUserIdentifier(), 'en'));
        self::assertNull($provider->resolve($reference($url, route: 'other', parameters: ['uuid' => $dm->getUuid()]), $ana->getUserIdentifier(), 'en'));
        self::assertNull($provider->resolve($reference($url), $ana->getUserIdentifier(), 'en'), 'no uuid parameter');
        self::assertNull($provider->resolve($reference('/chat/' . $dm->getUuid(), parameters: ['uuid' => $dm->getUuid()]), $ana->getUserIdentifier(), 'en'), 'no message');
        self::assertNull($provider->resolve($reference($url, parameters: ['uuid' => $dm->getUuid()]), $luis->getUserIdentifier(), 'en'), 'not the signed-in user');
        $this->signIn($this->user('Eve'));
        self::assertNull($provider->resolve($reference($url, parameters: ['uuid' => $dm->getUuid()]), $this->tokenUser(), 'en'), 'not readable');
    }

    /**
     * @param list<SearchFilter> $filters
     */
    private function searchQuery(TestUser $default, string $text, ?string $user = null, array $filters = [], ?string $scope = null): SearchQuery
    {
        return new SearchQuery($text, null, 5, $scope, false, $user ?? $default->getUserIdentifier(), $filters, 'en');
    }

    public function testProviderSharesTheBudget(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $chat->send($luis, $chat->openDirect($ana, $luis), 'budget line');
        $provider = $this->provider($this->get(ChatMessageSearch::class));
        $this->signIn($ana);
        // Two searches a minute here, for the page, the API and the palette together (audit VA-2).
        self::assertNotNull($provider->search($this->searchQuery($ana, 'budget')));
        $search = $this->get(ChatMessageSearch::class);
        self::assertCount(1, $search->search($this->fresh($ana), $search->query('budget'))->hits, 'the service itself is not limited');
        self::assertNotNull($provider->searchPage($this->searchQuery($ana, 'budget'), 1, 5));
        self::assertNull($provider->search($this->searchQuery($ana, 'budget')), 'over the budget: no rows');
    }

    private function provider(ChatMessageSearch $search): ChatMessageSearchProvider
    {
        return new ChatMessageSearchProvider($search, $this->get(Security::class), $this->get(UrlGeneratorInterface::class), $this->get(TranslatorInterface::class), $this->limiter());
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        $limiter = $this->kernel->getContainer()->get('limiter.nowo_chat_search');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    private function signIn(TestUser $user): void
    {
        $this->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function tokenUser(): string
    {
        return (string) $this->get(TokenStorageInterface::class)->getToken()?->getUserIdentifier();
    }

    private function fresh(TestUser $user): TestUser
    {
        $managed = $this->em->find(TestUser::class, $user->getId());
        self::assertInstanceOf(TestUser::class, $managed);

        return $managed;
    }
}
