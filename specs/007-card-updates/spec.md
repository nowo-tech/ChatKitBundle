# Feature specification: typed card updates (2.8.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (2.8.0, unreleased)
**Request**: Beacon `docs/ops/BUNDLES-REQUESTS/2026-11-27-teams-calls.md` item 2 (spec 260 T002): "the host updates or
ends a card after it is posted (*Call ended*), pushed through Mercure like an edit". The header action provider, card
actions in a new window and a copy-to-clipboard action kind are out of scope (later).
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md) · **Reference**: [`docs/USAGE.md`](../../docs/USAGE.md#card-updates-28)

## Summary

A typed card is rendered per viewer at read time (2.6). When the host's item changes (a Teams call ends), the open
clients still show the old render. The host now calls one BC-promised service after its commit, and every open client
renders the card again with its own permissions.

## User scenarios

- **US-01 (P1)**: the host ends a call; every open web dock and Beacon Desktop window shows the call card as "Call
  ended" without a reload.
- **US-02 (P1)**: a viewer who may not see the card still sees the neutral chip: the hint carries no card content.
- **US-03 (P2)**: a card shared in many messages refreshes the newest ones only (bounded work per call).

## Functional requirements

- **FR-CARDUP-001**: `Contract\ChatCardUpdatesInterface::changed(string $type, string $id, string $conversationUuid):
  int` (autowired alias of `Card\ChatCardUpdates`) sends the existing `message_updated` event for every message of
  that conversation that holds `#[…](type:id)` outside code (thread replies included, deleted messages left out), with
  the message's reactions, newest first; it returns how many were signalled.
- **FR-CARDUP-002**: scope and matching: one conversation by public uuid, uuid, type and id compared byte for byte; an
  unknown conversation, a type that is not a registered card type or a card no message holds sends nothing (0); a
  malformed type or id throws `InvalidArgumentException('invalid_card')`.
- **FR-CARDUP-003**: bounds: the newest `SCAN_WINDOW` (1000) top-level messages of the conversation, with the thread
  replies posted since, are searched with two indexed queries (the floor filters `threadRoot IS NULL`, audit ZCW-L1;
  `LIKE` with escaped `_`), at most `CANDIDATES` (80) rows are read, the exact token is checked, and at most
  `MAX_MESSAGES` (20) holders are signalled (cut after the filter, audit ZCW-I1).
- **FR-CARDUP-004**: the hint carries no card content (`full`: the message presented without a viewer, card slots
  pending; `ids`: ids only); the client API answers the re-read message with the card rendered for the caller. A
  database or hub failure is logged by class name and never thrown; the call is stateless (worker mode) and
  idempotent. Hosts call it after their transaction commits.

## Success criteria

- **SC-001**: tests cover the holders (thread reply, deleted, code span, look-alike ids, other conversation), the
  reactions in the payload, no card title on the hub, the bounds and the failure path; 100% line coverage.
