import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatApi } from '../src/api';
import { emojiSpan, fill, SupportPanel, type SupportHost } from '../src/support';
import type { ChatConfig, ConversationSummary, SupportIntake, SupportTicket } from '../src/types';

const i18n: Record<string, string> = {
  support_bot: 'Assistant', support_greeting: 'Hi! Pick a topic', support_topics_label: 'Topics', error: 'Error',
  support_resume_question: 'Last %number% (%topic% · %status%, %date%): continue or new?', support_continue: 'Continue %number%',
  support_new_ticket: 'New ticket', support_previous_tickets: 'Your tickets', support_ticket_label: '%number% · %topic% · %status%',
  support_reopen: 'Reopen', support_read_only_ticket: 'Closed ticket',
};

const ticket = (over: Partial<SupportTicket> = {}): SupportTicket => ({
  uuid: 't1', number: 'SUP-000001', topic: 'billing', topicLabel: 'Billing', status: 'open', statusLabel: 'Open',
  requester: { uuid: 'me', name: 'Me' }, assignee: null, createdAt: '2026-10-01T10:00:00Z', lastMessageAt: '2026-10-02T10:00:00Z',
  resolvedAt: null, readOnly: false, ...over,
});

const intake = (over: Partial<SupportIntake> = {}): SupportIntake => ({
  prompt: 'topic', topics: [{ key: 'incident', label: 'Incident', icon: null }, { key: 'billing', label: 'Billing', icon: 'tabler:receipt' }],
  resume: null, tickets: [], resumeAfterDays: 7, ...over,
});

const summary = (over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid: 'd1', kind: 'support', title: 'Support', subtitle: '', initials: '★', peerUuid: null, channelKey: null, supportStatus: 'open',
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: null, lastMessage: null, ticket: ticket({ uuid: 'd1', number: null }), intake: intake(), ...over,
});

function setup(api: Partial<Record<keyof ChatApi, unknown>> = {}) {
  const element = document.createElement('div');
  document.body.replaceChildren(element);
  const host: SupportHost = {
    config: { i18n, locale: 'en', userUuid: 'me' } as ChatConfig,
    api: api as unknown as ChatApi,
    conversationChanged: vi.fn(),
    openConversation: vi.fn(),
    appendMessages: vi.fn(),
    notice: vi.fn(),
  };
  const panel = new SupportPanel(host, element);
  const buttons = () => [...element.querySelectorAll('button')];
  const button = (label: string) => buttons().find((candidate) => candidate.textContent === label) as HTMLButtonElement;
  return { element, host, panel, buttons, button };
}

describe('SupportPanel', () => {
  beforeEach(() => {
    document.body.replaceChildren();
  });

  it('stays hidden outside support tickets and for drafts without intake', () => {
    const { element, panel } = setup();
    panel.render(summary({ kind: 'direct', ticket: undefined }));
    expect(element.hidden).toBe(true);
    panel.render(summary({ intake: undefined }));
    expect(element.hidden).toBe(true);
    panel.render(summary({ ticket: null }));
    expect(element.hidden).toBe(true);
    panel.render(summary({ ticket: undefined }));
    expect(element.hidden).toBe(true);
  });

  it('greets with the topics as buttons and opens a ticket with the chosen one', async () => {
    const chosen = { conversation: summary({ ticket: ticket() }), messages: [{ id: 1 }] };
    const chooseTopic = vi.fn().mockResolvedValue(chosen);
    const { element, host, panel, button } = setup({ chooseTopic });
    panel.render(summary());

    expect(element.hidden).toBe(false);
    expect(element.textContent).toContain('Hi! Pick a topic');
    const group = element.querySelector('[role="group"]') as HTMLElement;
    expect(group.getAttribute('aria-label')).toBe('Topics');
    const billing = button('Billing');
    expect(billing.type).toBe('button');
    expect(billing.dataset.topic).toBe('billing');
    billing.click();
    expect(billing.disabled).toBe(true);
    button('Incident').click();
    await vi.waitFor(() => expect(host.conversationChanged).toHaveBeenCalledWith(chosen.conversation));
    expect(chooseTopic).toHaveBeenCalledTimes(1);
    expect(chooseTopic).toHaveBeenCalledWith('d1', 'billing');
    expect(host.appendMessages).toHaveBeenCalledWith(chosen.messages);
    expect(billing.disabled).toBe(false);
  });

  it('asks continue or new, continues the ticket or switches to the topics', async () => {
    const resumed = summary({ uuid: 't1', ticket: ticket() });
    const resumeTicket = vi.fn().mockResolvedValue(resumed);
    const { element, host, panel, button } = setup({ resumeTicket });
    panel.render(summary({ intake: intake({ prompt: 'resume', resume: ticket({ status: 'resolved', statusLabel: 'Resolved' }) }) }));

    expect(element.textContent).toContain('Last SUP-000001 (Billing · Resolved, Oct 2, 2026): continue or new?');
    button('Continue SUP-000001').click();
    await vi.waitFor(() => expect(host.openConversation).toHaveBeenCalledWith(resumed));
    expect(resumeTicket).toHaveBeenCalledWith('t1', 'd1');

    button('New ticket').click();
    expect(document.activeElement).toBe(button('Incident'));
    expect(element.textContent).toContain('Hi! Pick a topic');
  });

  it('lists earlier tickets and opens one', async () => {
    const older = summary({ uuid: 't2', ticket: ticket({ uuid: 't2' }) });
    const conversation = vi.fn().mockResolvedValue(older);
    const { element, host, panel, button } = setup({ conversation });
    panel.render(summary({ intake: intake({ tickets: [ticket({ uuid: 't2', number: null })] }) }));

    const section = element.querySelector('section') as HTMLElement;
    expect(section.getAttribute('aria-label')).toBe('Your tickets');
    button(' · Billing · Open · Oct 2, 2026').click();
    await vi.waitFor(() => expect(host.openConversation).toHaveBeenCalledWith(older));
    expect(conversation).toHaveBeenCalledWith('t2');
  });

  it('shows the ticket status, Reopen for the requester and the read-only note', async () => {
    const reopened = summary({ ticket: ticket() });
    const setTicketStatus = vi.fn().mockRejectedValueOnce(new Error('x')).mockResolvedValue(reopened);
    const { element, host, panel, button } = setup({ setTicketStatus });

    panel.render(summary({ ticket: ticket() }));
    expect(element.textContent).toBe('SUP-000001 · Billing · Open');
    panel.render(summary({ ticket: ticket({ status: 'resolved', statusLabel: 'Resolved', requester: { uuid: 'other', name: 'Ana' } }) }));
    expect(element.querySelectorAll('button')).toHaveLength(0);

    panel.render(summary({ ticket: ticket({ status: 'resolved', statusLabel: 'Resolved' }) }));
    expect(element.querySelector('.is-resolved')).not.toBeNull();
    button('Reopen').click();
    await vi.waitFor(() => expect(host.notice).toHaveBeenCalledWith('Error'));
    button('Reopen').click();
    await vi.waitFor(() => expect(host.conversationChanged).toHaveBeenCalledWith(reopened));
    expect(setTicketStatus).toHaveBeenCalledWith('t1', 'open');

    panel.render(summary({ ticket: ticket({ status: 'closed', statusLabel: 'Closed', readOnly: true, requester: null }) }));
    expect(element.textContent).toContain('Closed ticket');
  });

  it('puts decorative aria-hidden emojis before the status, the topic and the assistant lines', () => {
    const { element, host, panel, button } = setup();
    const hidden = () => [...element.querySelectorAll('.nowo-chat-deco')].map((span) => [span.textContent, span.getAttribute('aria-hidden')]);

    panel.render(summary({ ticket: ticket({ topicEmoji: '💳', statusEmoji: '🟢' }) }));
    expect(element.textContent).toBe('SUP-000001 · 💳Billing · 🟢Open');
    expect(hidden()).toEqual([['💳', 'true'], ['🟢', 'true']]);
    // 1.9.2 regression: the emojis sit inside the status line, never with the emoji picker's popover class.
    const line = element.querySelector('.nowo-chat-support__status') ?? element;
    expect(line.querySelectorAll('.nowo-chat-deco')).toHaveLength(2);
    expect(element.querySelector('.nowo-chat-emoji')).toBeNull();

    panel.render(summary({ intake: intake({ topics: [{ key: 'billing', label: 'Billing', icon: null, emoji: '💳' }] }) }));
    expect(hidden()).toEqual([['👋', 'true'], ['💳', 'true']]);
    expect(button('💳Billing').dataset.topic).toBe('billing');

    panel.render(summary({ intake: intake({ prompt: 'resume', resume: ticket({ topicEmoji: '💳', statusEmoji: '✅' }) }) }));
    expect(hidden().map(([emoji]) => emoji)).toEqual(['🔄', '💳', '✅']);

    host.config.supportEmojis = false;
    panel.render(summary());
    expect(hidden()).toEqual([]);
  });

  it('fills translated templates with nodes and keeps unknown placeholders', () => {
    expect(emojiSpan('')).toBeNull();
    expect(emojiSpan(undefined)).toBeNull();
    expect(fill('%a% and %b%', { a: ['x'] })).toEqual(['', 'x', ' and ', '%b%', '']);
  });
});

// Vitest runs from the bundle root.
const CSS_SOURCE = readFileSync('src/Resources/public/css/nowo-chat.css', 'utf8');

describe('decorative emoji styles (1.9.2)', () => {
  it('are inline only: no positioning, unlike the emoji picker popover', () => {
    const rule = CSS_SOURCE.match(/\.nowo-chat-deco\s*\{([^}]*)\}/)?.[1] ?? '';
    expect(rule).toContain('display: inline-block');
    expect(rule).not.toMatch(/position|box-shadow|background/);
    expect(CSS_SOURCE).toMatch(/\.nowo-chat-emoji\s*\{[^}]*position: absolute/);
  });
});

describe('message times (1.10.1)', () => {
  it('reveal on hover without moving anything: no display, height or margin change', () => {
    const rules = [...CSS_SOURCE.matchAll(/([^{}]*\.nowo-chat-msg[^{}\s]*:(?:hover|focus-within)[^{}]*\.nowo-chat-msg__meta[^{}]*)\{([^}]*)\}/g)];
    expect(rules.length).toBeGreaterThan(0);
    for (const [, , body] of rules) {
      expect(body).not.toMatch(/display|height|margin|padding|position/);
    }
    expect(CSS_SOURCE).not.toMatch(/^\.nowo-chat-msg__meta\s*\{[^}]*max-height/m);
  });
});
