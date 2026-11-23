<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function str_starts_with;

/**
 * 1.34: shapes the lock-conflict answer of nowo-tech/transaction-doctrine's responder for ChatKit's own JSON API routes.
 *
 * A host that lists ChatKit routes in `nowo_transaction_doctrine.controller_routes` gets ChatKit's
 * `{"ok": false, "error": "unavailable", "code": "CONFLICT_00x"}` (1.35: and the `X-Conflict-Code` header) instead of the library's `{"error": "concurrency_conflict"}`; when the conflict
 * already committed, a `message` asks to reload. Status, `Retry-After` (none when committed) and `Cache-Control` stay the
 * responder's. The chat page routes and every other request fall through (`null`).
 *
 * Stateless (safe in worker mode without a kernel reset).
 */
final readonly class ChatConflictResponseFormatter implements ConflictResponseFormatterInterface
{
    /** Route names of ChatKit's JSON API. */
    private const string API_ROUTE_PREFIX = 'nowo_chat_api_';

    /**
     * Builds the conflict response for the given request and context, or null when this formatter does not apply.
     */
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::API_ROUTE_PREFIX)) {
            return null;
        }
        // 1.35 (REQ-RUNTIME-003 rule 6): the conflict code in the body and in the X-Conflict-Code header.
        $body = ['ok' => false, 'error' => 'unavailable', 'code' => $context->code->value];
        if ($context->committed) {
            $body['message'] = ChatApiExceptionListener::PARTIAL_MESSAGE;
        }

        return new JsonResponse($body, $context->status, [ChatApiExceptionListener::CODE_HEADER => $context->code->value]);
    }
}
