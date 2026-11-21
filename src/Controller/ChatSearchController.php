<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Form\ChatMessageSearchType;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatMessageSearchHit;
use Nowo\ChatKitBundle\Search\ChatMessageSearchPage;
use Nowo\ChatKitBundle\Search\ChatMessageSearchQuery;
use Nowo\ChatKitBundle\Search\ChatSearchQueryException;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\View\ChatPage;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Label;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

use const PHP_URL_PATH;

/**
 * 1.37 (spec 002): message search — the page `GET /chat/search` (FormKit form, works without JavaScript) and the JSON
 * endpoint `GET /chat/api/search`. Both answer 404 while `search.enabled` is off, need a signed-in reachable participant,
 * share the `limits.searches_per_minute` budget and show only what {@see ChatMessageSearch} returns for the viewer.
 * Unknown and forbidden filter values give the same answer. Nothing is kept between requests (FrankenPHP worker mode).
 */
final class ChatSearchController extends AbstractController
{
    /** Limiter key prefix of the page and API search budget (the palette has its own, audit VA3-2). */
    public const string LIMITER_KEY = 'nowo_chat_search_page_';

    /** 1.40: header of the results fragment, so the modal never renders another answer (a login page) as results. */
    public const string FRAGMENT_HEADER = 'X-Nowo-Chat-Fragment';

    /**
     * @param ChatMessageSearch $search Message search
     * @param ChatAccess $access Reachability of the signed-in participant
     * @param RateLimiterFactoryInterface $searchLimiter `limits.searches_per_minute`
     * @param string|null $webUiLayout Layout of the page (`web_ui.layout_template`); null = nowo_ui_kit.layouts.admin
     * @param string|null $layout @deprecated since 2.0, ignored (the 1.x `page_layout`, removed in 2.0); removed in 3.0
     * @param bool $assets Whether the page prints the nowo-chat.css tag (`web_ui.assets`)
     * @param bool $fullHeight Whether the page fills the host content area (`web_ui.page.full_height`)
     * @param int $maxQueryLength `search.max_query_length` (the input's maxlength)
     * @param ChatCurrentUserPort|null $currentUser The signed-in user's chat participant
     */
    public function __construct(
        private readonly ChatMessageSearch $search,
        private readonly ChatAccess $access,
        #[Autowire(service: 'limiter.nowo_chat_search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
        #[Autowire(param: 'nowo_chat_kit.web_ui.layout_template')]
        private readonly ?string $webUiLayout = null,
        ?string $layout = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.assets')]
        private readonly bool $assets = true,
        #[Autowire(param: 'nowo_chat_kit.web_ui.page.full_height')]
        private readonly bool $fullHeight = false,
        #[Autowire(param: 'nowo_chat_kit.search.max_query_length')]
        private readonly int $maxQueryLength = 200,
        private readonly ?ChatCurrentUserPort $currentUser = null,
    ) {
    }

    /**
     * The search page: the form, then (with a text) one page of results with the words marked and a "More results" link.
     *
     * @param Request $request Query `q`, `from`, `after`, `before`, `link`, `conversation`, `cursor`
     *
     * @return Response The page (429 over the budget, 404 when search is off)
     */
    #[Route('/search', name: 'nowo_chat_page_search', methods: ['GET'])]
    public function page(Request $request): Response
    {
        if (!$this->search->enabled()) {
            throw $this->createNotFoundException();
        }
        $user = $this->participant();
        if (!$user instanceof ChatUserInterface) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->container->get('form.factory')->createNamed('', ChatMessageSearchType::class, null, ['max_length' => $this->maxQueryLength]);
        $form->handleRequest($request);
        // The form only shows the values; the search reads and checks the parameters itself.
        [$status, $error, $page] = $this->run($user, $request, false);

        $back = $this->backHref($request);

        return $this->render('@NowoChatKitBundle/search.html.twig', [
            'layout'                => $this->webUiLayout,
            'header'                => ChatPage::searchHeader(new BackLink($back, new Label('chat_search.back', ChatPage::BUNDLE), 'chat-search-back')),
            'back'                  => $back,
            'filters_active'        => $this->filtersActive($request),
            'bundle'                => ChatPage::BUNDLE,
            'form'                  => $form->createView(),
            'searched'              => $page instanceof ChatMessageSearchPage,
            'error'                 => $error,
            'items'                 => $page instanceof ChatMessageSearchPage ? array_map($this->pageItem(...), $page->hits) : [],
            'next'                  => $page?->next === null ? null : $this->generateUrl('nowo_chat_page_search', ['cursor' => $page->next] + $request->query->all()),
            'nowo_chat_assets'      => $this->assets,
            'nowo_chat_full_height' => $this->fullHeight,
        ], new Response(null, $status));
    }

    /**
     * 1.40: the search modal's results, an HTML fragment (`_search_results.html.twig`): the same search, budget and
     * escaping as the page. `scope=all` searches every conversation the viewer may read; any other value keeps the
     * `conversation` filter (the open conversation), which the search checks like any other value (an unknown or
     * forbidden conversation finds nothing); without `conversation` it is the same as `all`. GET only, reads only (no
     * CSRF token); nothing is kept between requests. The answer carries {@see self::FRAGMENT_HEADER}.
     *
     * @param Request $request Query `q`, `scope`, `conversation`, `from`, `after`, `before`, `link`, `cursor`
     *
     * @return Response The fragment (400 / 429 with the notice, 401 without a participant, 404 when search is off)
     */
    #[Route('/search/results', name: 'nowo_chat_search_results', methods: ['GET'])]
    public function results(Request $request): Response
    {
        if (!$this->search->enabled()) {
            throw $this->createNotFoundException();
        }
        $user = $this->participant();
        if (!$user instanceof ChatUserInterface) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }
        $all                     = $request->query->get('scope') === ChatMessageSearchType::SCOPE_ALL;
        [$status, $error, $page] = $this->run($user, $request, $all);
        $query                   = $request->query->all();
        if ($all) {
            unset($query['conversation']);
        }

        return $this->render('@NowoChatKitBundle/_search_results.html.twig', [
            'bundle'   => ChatPage::BUNDLE,
            'searched' => $page instanceof ChatMessageSearchPage,
            'error'    => $error,
            'items'    => $page instanceof ChatMessageSearchPage ? array_map($this->pageItem(...), $page->hits) : [],
            'next'     => $page?->next === null ? null : $this->generateUrl('nowo_chat_search_results', ['cursor' => $page->next] + $query),
        ], new Response(null, $status, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', self::FRAGMENT_HEADER => 'search-results']));
    }

    /**
     * Runs the search of the page or the modal: nothing without a text; over the budget, 429; a bad text, 400.
     *
     * @param bool $all 1.40: true drops the `conversation` filter (the modal's "all my conversations")
     *
     * @return array{0: int, 1: string|null, 2: ChatMessageSearchPage|null} The status, the notice key and the page
     */
    private function run(ChatUserInterface $user, Request $request, bool $all): array
    {
        $text = $request->query->get('q');
        if (!is_string($text) || $text === '') {
            return [Response::HTTP_OK, null, null];
        }
        if (!$this->withinBudget($user)) {
            return [Response::HTTP_TOO_MANY_REQUESTS, 'chat_search.rate_limited', null];
        }

        try {
            return [Response::HTTP_OK, null, $this->search->search($user, $this->queryFrom($request, $text, $all))];
        } catch (ChatSearchQueryException $exception) {
            return [Response::HTTP_BAD_REQUEST, $exception->errorCode === ChatSearchQueryException::TOO_LONG ? 'chat_search.too_long' : 'chat_search.too_short', null];
        }
    }

    /**
     * The JSON endpoint: `{items: list<{uuid, threadRootUuid, conversation: {uuid, kind, title}, author: {name, publicId}|null,
     * createdAt, snippet, link}>, next}`. Plain text only (the client escapes it).
     *
     * @param Request $request Query `q`, `conversation`, `from`, `after`, `before`, `link`, `cursor`
     *
     * @return JsonResponse The page (400 `query_too_short` / `query_too_long`, 401, 404, 429 `rate_limited`)
     */
    #[Route('/api/search', name: 'nowo_chat_api_search', methods: ['GET'])]
    public function api(Request $request): JsonResponse
    {
        if (!$this->search->enabled()) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }
        $user = $this->participant();
        if (!$user instanceof ChatUserInterface) {
            throw new ChatApiException('unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->withinBudget($user)) {
            throw new ChatApiException('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $text = $request->query->get('q');

        try {
            $page = $this->search->search($user, $this->queryFrom($request, is_string($text) ? $text : ''));
        } catch (ChatSearchQueryException $exception) {
            throw new ChatApiException($exception->errorCode, Response::HTTP_BAD_REQUEST, $exception);
        }

        return $this->json([
            'items' => array_map(fn (ChatMessageSearchHit $hit): array => $hit->toArray() + ['link' => $this->link($hit)], $page->hits),
            'next'  => $page->next,
        ]);
    }

    /**
     * @return array{hit: ChatMessageSearchHit, link: string}
     */
    private function pageItem(ChatMessageSearchHit $hit): array
    {
        return ['hit' => $hit, 'link' => $this->link($hit)];
    }

    private function link(ChatMessageSearchHit $hit): string
    {
        return $this->generateUrl('nowo_chat_page_conversation', ['uuid' => $hit->conversationUuid, 'message' => $hit->messageUuid]);
    }

    /**
     * The query from the request parameters (the form only reads them for its own view). `after` and `before` are dates
     * (`Y-m-d`), both days included; any other value is ignored.
     */
    private function queryFrom(Request $request, string $text, bool $all = false): ChatMessageSearchQuery
    {
        $string = static fn (string $key): ?string => is_string($value = $request->query->get($key)) ? $value : null;
        $date   = static function (?string $value): ?DateTimeImmutable {
            if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                return null;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date === false ? null : $date;
        };
        $before = $date($string('before'));

        return $this->search->query(
            $text,
            $all ? null : $string('conversation'),
            $string('from'),
            $date($string('after')),
            // "Before" is inclusive of that day in the form: the search takes messages before the next day.
            $before?->modify('+1 day'),
            $request->query->getBoolean('link'),
            $string('cursor'),
        );
    }

    /**
     * 1.39.1: where the page's back link goes — the referring page when it is a page of this site (same scheme, host
     * and port; returned as a path, never as an absolute URL), otherwise the chat home. The search page itself, a
     * protocol-relative or backslash path and any other origin fall back to the chat home (no open redirect).
     */
    private function backHref(Request $request): string
    {
        $home    = $this->generateUrl('nowo_chat_page');
        $referer = $request->headers->get('referer');
        $origin  = $request->getSchemeAndHttpHost();
        if (!is_string($referer) || $referer === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $referer) === 1 || !str_starts_with($referer, $origin . '/')) {
            return $home;
        }
        $path = substr($referer, strlen($origin));
        if (str_starts_with($path, '//') || parse_url($path, PHP_URL_PATH) === $this->generateUrl('nowo_chat_page_search')) {
            return $home;
        }

        return $path;
    }

    /**
     * 1.39.1: whether a filter of the form has a value other than its default (the filters disclosure then starts open).
     */
    private function filtersActive(Request $request): bool
    {
        foreach (['from', 'after', 'before'] as $key) {
            $value = $request->query->get($key);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return $request->query->getBoolean('link');
    }

    private function participant(): ?ChatUserInterface
    {
        $user = $this->getUser();
        $user = $this->currentUser instanceof ChatCurrentUserPort ? $this->currentUser->resolve($user) : $user;

        return $user instanceof ChatUserInterface && $this->access->isReachable($user) ? $user : null;
    }

    private function withinBudget(ChatUserInterface $user): bool
    {
        // Audit VA3-2: `…_page_<id>` and the palette's `…_palette_<id>` can never be the same key.
        return $this->searchLimiter->create(self::LIMITER_KEY . $user->getChatPublicId())->consume()->isAccepted();
    }
}
