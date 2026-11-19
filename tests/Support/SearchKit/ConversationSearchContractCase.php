<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\SearchKit;

use DateTimeImmutable;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\SearchKit\ChatConversationSearchDefinition;
use Nowo\ChatKitBundle\SearchKit\ChatConversationSearchProvider;
use Nowo\ChatKitBundle\SearchKit\ChatSearchKitViewer;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportPolicy;
use Nowo\ChatKitBundle\Support\ChatSupportTopics;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function sort;

/**
 * 1.38: the SearchKit contract (EntitySearchProviderTestCase) of the conversation group (`chat_channels`), plus the
 * rules of ChatKit's own pages it must keep: names per viewer, non-members, channels listed ∩ usable, tickets for
 * their owner and every agent, closed conversations, literal wildcards, a query budget constant with size. A tenant is
 * a viewer (ChatKit has no tenants); a seeded row is a direct conversation with someone whose name holds the text.
 */
abstract class ConversationSearchContractCase extends EntitySearchProviderTestCase
{
    use SearchKitContractKernel;

    private ScriptedChannelProvider $channels;

    private int $ticketNumber = 100;

    abstract protected function realDatabase(): bool;

    protected function setUp(): void
    {
        $this->bootContractKernel($this->realDatabase());
        $this->channels = new ScriptedChannelProvider();
    }

    protected function tearDown(): void
    {
        $this->shutdownContractKernel();
    }

    protected function definition(): ChatConversationSearchDefinition
    {
        return $this->definitionWith(20, 500);
    }

    protected function queryBudget(): int
    {
        // The viewer's direct conversations, their people (one directory call), the search query.
        return 3;
    }

    protected function sensitiveTitles(): bool
    {
        return true;
    }

    protected function seed(int|string $tenant, string $text): int
    {
        return (int) $this->service(ChatService::class)->openDirect($this->viewerOf($tenant), $this->person('Peer ' . $text))->getId();
    }

    protected function seedHidden(int|string $tenant, string $text): int
    {
        // A participant row that grants nothing: the conversation of two other people (the pair check hides it).
        $conversation = $this->service(ChatService::class)->openDirect($this->person('Peer ' . $text), $this->person('Someone'));
        $this->em->persist(new ChatParticipant($conversation, $this->viewerOf($tenant)));
        $this->em->flush();

        return (int) $conversation->getId();
    }

    protected function seedArchived(int|string $tenant, string $text): int
    {
        // A channel the viewer left: the provider still knows it and lets them use it by key, but no longer lists it.
        $channel = new ChatChannel('kit:' . $text, 'Room ' . $text);
        $this->channels->add($channel, $this->viewerOf($tenant), false);

        return (int) $this->channelConversation($channel)->getId();
    }

    protected function reference(int|string $id): SearchReference
    {
        $conversation = $this->em->find(ChatConversation::class, $id);
        self::assertInstanceOf(ChatConversation::class, $conversation);

        return new SearchReference($this->url($conversation), ChatConversationSearchDefinition::KIND, ChatConversationSearchDefinition::ROUTE, ['uuid' => $conversation->getUuid()]);
    }

    public function testDirectConversationsAreNamedPerViewer(): void
    {
        [$a]  = $this->tenants();
        $ana  = $this->viewerOf($a);
        $luis = $this->person('Luis Quokka');
        $dm   = $this->service(ChatService::class)->openDirect($ana, $luis);

        $this->actAs($a, self::VIEWER_A);
        $group = $this->provider()->search($this->query('quokka'));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame('chat_channels', $group->key);
        self::assertSame('Conversations', $group->label);
        self::assertNull($group->moreUrl);
        self::assertCount(1, $group->items);
        $item = $group->items[0];
        self::assertSame('Luis Quokka', $item->title);
        self::assertSame('Direct message', $item->subtitle, 'never the other person\'s e-mail address');
        self::assertSame($this->url($dm), $item->url);
        self::assertSame(ChatConversationSearchDefinition::KIND, $item->kind);
        // Accents and case do not matter; every word must be in the name; the viewer's own name is not the title.
        self::assertCount(1, $this->provider()->search($this->query('LUIS quokká'))->items ?? []);
        self::assertNull($this->provider()->search($this->query('luis nobody')));
        self::assertNull($this->provider()->search($this->query('viewer ana')));

        // Luis sees the same conversation under Ana's name.
        $this->login($luis);
        $group = $this->provider()->search($this->query('viewer ana', 10, null, $luis->getUserIdentifier()));
        self::assertSame([$this->url($dm)], $this->urlsOf($group));
        self::assertSame('Viewer Ana', $group?->items[0]->title);

        // Someone else never finds it, by either name.
        $eve = $this->person('Eve');
        $this->login($eve);
        self::assertNull($this->provider()->search($this->query('quokka', 10, null, $eve->getUserIdentifier())));
        self::assertNull($this->provider()->search($this->query('viewer ana', 10, null, $eve->getUserIdentifier())));

        // A closed (archived) conversation still opens: it is still found.
        $this->service(ChatService::class)->archive($ana, [$dm], true);
        $this->actAs($a, self::VIEWER_A);
        self::assertSame([$this->url($dm)], $this->urlsOf($this->provider()->search($this->query('quokka'))));
    }

    public function testChannelsAreListedAndUsable(): void
    {
        [$a]       = $this->tenants();
        $ana       = $this->viewerOf($a);
        $open      = $this->channelConversation($channel = new ChatChannel('project:alpha', 'Alpha Wombat', false, 'Topic of alpha'));
        $readOnly  = $this->channelConversation($archive = new ChatChannel('project:beta', 'Beta Wombat', true));
        $forbidden = $this->channelConversation($locked = new ChatChannel('project:gamma', 'Gamma Wombat'));
        $this->channelConversation($left = new ChatChannel('project:delta', 'Delta Wombat'));
        $this->channels->add($channel, $ana);
        $this->channels->add($archive, $ana);
        $this->channels->add($locked, $ana, true, false);
        $this->channels->add($left, $ana, false);
        // Listed and usable, but nobody opened it yet: no conversation, nothing to link to.
        $this->channels->add(new ChatChannel('project:epsilon', 'Epsilon Wombat'), $ana);

        $this->actAs($a, self::VIEWER_A);
        $group = $this->provider()->search($this->query('wombat'));
        self::assertSame($this->sorted([$this->url($open), $this->url($readOnly)]), $this->sorted($this->urlsOf($group)));
        self::assertSame(2, $group?->total);
        $alpha = $this->provider()->search($this->query('#alpha'));
        self::assertInstanceOf(SearchGroup::class, $alpha);
        self::assertSame([$this->url($open)], $this->urlsOf($alpha));
        self::assertSame('#Alpha Wombat', $alpha->items[0]->title);
        self::assertSame('Topic of alpha', $alpha->items[0]->subtitle);
        self::assertNull($this->provider()->search($this->query('gamma')), 'listed, but not usable');
        self::assertNull($this->provider()->search($this->query('delta')), 'usable by key, but not listed (left)');
        self::assertNull($this->provider()->search($this->query('epsilon')));
        // Only the label is matched, never the channel key or its members.
        self::assertNull($this->provider()->search($this->query('project')));
        self::assertNotNull($forbidden->getId());
    }

    public function testTicketsForTheirOwnerAndEveryAgent(): void
    {
        [$a]       = $this->tenants();
        $ana       = $this->viewerOf($a);
        $ticket    = $this->ticket($ana, 'billing');
        $other     = $this->ticket($this->person('Eve Customer'), 'billing');
        $unknown   = $this->ticket($ana, 'old_topic');
        $draft     = $this->service(ChatService::class)->openSupport($this->person('Draft Dan'));
        $formatted = (string) $this->service(ChatSupportPolicy::class)->format($ticket->getTicketNumber());

        $this->actAs($a, self::VIEWER_A);
        self::assertSame($this->sorted([$this->url($ticket)]), $this->sorted($this->urlsOf($this->provider()->search($this->query('billing')))));
        $group = $this->provider()->search($this->query($formatted));
        self::assertSame([$this->url($ticket)], $this->urlsOf($group));
        self::assertSame($formatted . ' · Billing', $group?->items[0]->title);
        self::assertSame([$this->url($ticket)], $this->urlsOf($this->provider()->search($this->query((string) $ticket->getTicketNumber() . ' bill'))));
        // A topic that is no longer configured: its stored key is its label, matched literally.
        self::assertSame([$this->url($unknown)], $this->urlsOf($this->provider()->search($this->query('old_topic'))));
        self::assertNull($this->provider()->search($this->query('old%topic')));
        // A word of the prefix: every ticket of the viewer.
        self::assertCount(2, $this->provider()->search($this->query('sup'))->items ?? []);

        // An agent sees every ticket (the inbox and the conversation page allow it), titled by its owner, found by its label.
        $agent = $this->person('Agent Alice');
        $this->login($agent);
        $group = $this->provider()->search($this->query('billing', 10, null, $agent->getUserIdentifier()));
        self::assertSame($this->sorted([$this->url($ticket), $this->url($other)]), $this->sorted($this->urlsOf($group)));
        $titles = array_map(static fn (SearchItem $item): string => $item->title, $group->items ?? []);
        sort($titles);
        self::assertSame(['Eve Customer', 'Viewer Ana'], $titles);
        // Never by the owner's name (the label is the ticket's name), never an intake draft.
        self::assertNull($this->provider()->search($this->query('customer', 10, null, $agent->getUserIdentifier())));
        self::assertNull($this->provider()->search($this->query('support', 10, null, $agent->getUserIdentifier())));
        self::assertNotNull($draft->getId());

        // A ticket from before topics (no topic): "No topic" is its label's topic.
        $this->em->createQueryBuilder()->update(ChatConversation::class, 'c')->set('c.ticketTopic', ':none')->where('c.id = :id')
            ->setParameter('none', null)->setParameter('id', $other->getId())->getQuery()->execute();
        $this->em->clear();
        $group = $this->provider()->search($this->query('no topic', 10, null, $agent->getUserIdentifier()));
        self::assertSame([$this->url($other)], $this->urlsOf($group));
    }

    public function testQueryBudgetDoesNotGrowWithResults(): void
    {
        [$a]   = $this->tenants();
        $small = $this->marker();
        $large = $this->marker();
        for ($row = 0; $row < 2; ++$row) {
            $this->seed($a, $small . 'x' . $row);
        }
        for ($row = 0; $row < 12; ++$row) {
            $this->seed($a, $large . 'x' . $row);
        }
        $channel = new ChatChannel('kit:budget', 'Budget ' . $large);
        $this->channels->add($channel, $this->viewerOf($a));
        $this->channelConversation($channel);
        $this->ticket($this->viewerOf($a), 'billing');
        $this->actAs($a, self::VIEWER_A);

        $counts = [];
        foreach ([$small => 2, $large => 13] as $marker => $rows) {
            // A fresh request each time: nothing learnt by the previous search helps.
            $this->freshRequest();
            $counter = $this->queryCounter();
            $counter->reset();
            self::assertCount($rows, $this->provider()->search($this->query($marker, 20))->items ?? []);
            $counts[] = $counter->count();
        }
        self::assertSame($counts[0], $counts[1]);
        self::assertLessThanOrEqual($this->queryBudget(), $counts[1]);
    }

    /**
     * Audit VO-1: a full direct scan marks the total as not exact (older direct conversations were not looked at); the
     * note belongs to that one search.
     */
    public function testAFullDirectScanIsNotAnExactTotal(): void
    {
        [$a]    = $this->tenants();
        $marker = $this->marker();
        for ($row = 0; $row < 3; ++$row) {
            $this->seed($a, $marker . 'p' . $row);
        }
        $this->actAs($a, self::VIEWER_A);
        $cut   = new ChatConversationSearchProvider($this->definitionWith(20, 2));
        $group = $cut->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(2, $group->items);
        self::assertSame(2, $group->total);
        self::assertFalse($group->exactTotal, 'two of three direct conversations scanned: the total is a lower bound');
        $whole = new ChatConversationSearchProvider($this->definitionWith(20, 10));
        self::assertTrue($whole->search($this->query($marker))?->exactTotal);
        // Nothing is left on the request once read; a search with no match leaves no note either.
        self::assertNull($cut->searchPage($this->query($marker . 'none'), 1, 5));
        self::assertFalse($this->definitionWith(20, 2)->takeScanCut($this->query($marker)));
        // Without a request there is nothing to note: the total stays SearchKit's.
        $stack = $this->service(RequestStack::class);
        while ($stack->pop() instanceof Request) {
        }
        self::assertCount(2, $cut->search($this->query($marker))->items ?? []);
        $this->freshRequest();
    }

    /**
     * Audit VO-2: the group's own bucket of `limits.searches_per_minute`, one token per search; recents spend nothing.
     */
    public function testTheGroupHasItsOwnBudget(): void
    {
        [$a]    = $this->tenants();
        $marker = $this->marker();
        $id     = $this->seed($a, $marker);
        $this->actAs($a, self::VIEWER_A);
        $limiter  = new RateLimiterFactory(['id' => 'chat_kit_test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $provider = new ChatConversationSearchProvider($this->definitionWith(20, 10, $limiter));
        self::assertNotNull($provider->search($this->query($marker)));
        self::assertNotNull($provider->searchPage($this->query($marker), 1, 5));
        self::assertNull($provider->search($this->query($marker)), 'over the budget: no rows');
        self::assertNotNull($provider->resolve($this->reference($id), self::VIEWER_A, null), 'recents are not searches');
        self::assertTrue($limiter->create(ChatConversationSearchDefinition::LIMITER_KEY . 'other')->consume()->isAccepted(), 'per viewer');
        self::assertStringStartsNotWith('nowo_chat_search_page_', ChatConversationSearchDefinition::LIMITER_KEY);
        self::assertStringStartsNotWith('nowo_chat_search_palette_', ChatConversationSearchDefinition::LIMITER_KEY);
    }

    public function testEveryRowIsCheckedAgain(): void
    {
        [$a, $b] = $this->tenants();
        $marker  = $this->marker();
        $id      = $this->seed($a, $marker);
        $hidden  = $this->seedHidden($a, $marker . 'h');
        $visible = $this->em->find(ChatConversation::class, $id);
        $other   = $this->em->find(ChatConversation::class, $hidden);
        self::assertInstanceOf(ChatConversation::class, $visible);
        self::assertInstanceOf(ChatConversation::class, $other);
        $rows = [$visible, $other, ['id' => 1]];
        $this->actAs($a, self::VIEWER_A);
        $definition = $this->definition();
        $query      = $this->query($marker);

        self::assertSame([$rows[0]], $definition->filterVisible($rows, $a, $query), 'the pair check, and only conversations');
        self::assertSame([], $definition->filterVisible($rows, $b, $query), 'another tenant than the signed-in viewer');
        self::assertSame([], $definition->filterVisible($rows, $a, $this->query($marker . 'zz')), 'the name no longer matches');
        self::assertFalse($definition->featureEnabled($b, $query));
        self::assertSame('1 = 0', (string) $definition->baseQuery($b, $query)->getDQLPart('where'));
        // prepareItems() skips anything that is not a conversation.
        $context = new SearchItemContext();
        $definition->prepareItems([['id' => 1]], $query, $context);
        self::assertSame([], $context->get('chat_people'));
        $this->actAs(null, self::VIEWER_A);
        $this->expectException(LogicException::class);
        $definition->toItem($rows[0], $query);
    }

    public function testReferencesAreShapeChecked(): void
    {
        $definition = $this->definition();
        $uuid       = '0198c2e4-5b1a-7c3d-8e9f-0a1b2c3d4e5f';
        self::assertSame($uuid, $definition->referenceId(new SearchReference('/chat/' . $uuid, ChatConversationSearchDefinition::KIND, ChatConversationSearchDefinition::ROUTE, ['uuid' => $uuid])));
        foreach ([
            new SearchReference('/chat/' . $uuid, 'chat_message', ChatConversationSearchDefinition::ROUTE, ['uuid' => $uuid]),
            new SearchReference('/chat/' . $uuid, ChatConversationSearchDefinition::KIND, 'other', ['uuid' => $uuid]),
            new SearchReference('/chat/x', ChatConversationSearchDefinition::KIND, ChatConversationSearchDefinition::ROUTE, ['uuid' => $uuid . "\n"]),
            new SearchReference('/chat/x', ChatConversationSearchDefinition::KIND, ChatConversationSearchDefinition::ROUTE, ['uuid' => 7]),
            new SearchReference('/chat/x', ChatConversationSearchDefinition::KIND, ChatConversationSearchDefinition::ROUTE, []),
        ] as $reference) {
            self::assertNull($definition->referenceId($reference));
        }
        self::assertSame('c.uuid', $definition->idField());
        self::assertSame(1, $this->definitionWith(0, 0)->maxCandidates());
        self::assertSame(1000, $this->definitionWith(5000, 0)->maxCandidates());
        self::assertSame(['c.channelKey'], $definition->searchableFields());
        $provider = new ChatConversationSearchProvider($definition);
        self::assertSame('chat_channels', $provider->groupKey());
        self::assertNull($provider->searchPage(new SearchQuery('x', null, 5, null, false, self::VIEWER_A), 1, 5));

        // The wrapper SearchKit registers: search, pages and recents of the generic provider.
        [$a]    = $this->tenants();
        $marker = $this->marker();
        $id     = $this->seed($a, $marker);
        $this->actAs($a, self::VIEWER_A);
        self::assertCount(1, $provider->search($this->query($marker))->items ?? []);
        self::assertCount(1, $provider->searchPage($this->query($marker), 1, 5)->items ?? []);
        self::assertSame($this->reference($id)->url, $provider->resolve($this->reference($id), self::VIEWER_A, null)?->url);
        // No words (only white space reaches the hook): nothing.
        $qb = $this->service(ChatConversationRepository::class)->createQueryBuilder('c');
        self::assertTrue($definition->applyFullText($qb, [], $this->query(' ')));
        self::assertSame('1 = 0', (string) $qb->getDQLPart('where'));
    }

    private function definitionWith(int $maxCandidates, int $directScan, ?RateLimiterFactoryInterface $limiter = null): ChatConversationSearchDefinition
    {
        $limiter ??= $this->kernel->getContainer()->get('limiter.nowo_chat_search');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $access = new ChatAccess(
            $this->service(TestAccessPolicy::class),
            $this->channels,
            $this->service(ChatDirectoryInterface::class),
            $this->service(RequestStack::class),
            $this->service(ChatUserReferences::class),
        );

        return new ChatConversationSearchDefinition(
            $this->service(ChatConversationRepository::class),
            new ChatSearchKitViewer($this->service(Security::class), $access),
            $access,
            $this->channels,
            $this->service(ChatPresenter::class),
            $this->service(ChatSupportTopics::class),
            $this->service(ChatSupportPolicy::class),
            $this->service(UrlGeneratorInterface::class),
            $this->service(TranslatorInterface::class),
            $limiter,
            $this->service(RequestStack::class),
            $maxCandidates,
            $directScan,
        );
    }

    private function login(TestUser $user): void
    {
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function channelConversation(ChatChannel $channel): ChatConversation
    {
        $conversation = ChatConversation::channel($channel->key, new DateTimeImmutable());
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    private function ticket(TestUser $owner, string $topic): ChatConversation
    {
        $conversation = ChatConversation::support($owner, new DateTimeImmutable());
        $conversation->openTicket(++$this->ticketNumber, $topic);
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    private function url(ChatConversation $conversation): string
    {
        return $this->service(UrlGeneratorInterface::class)->generate(ChatConversationSearchDefinition::ROUTE, ['uuid' => $conversation->getUuid()]);
    }

    /**
     * @return list<string>
     */
    private function urlsOf(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->url, $group->items) : [];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
