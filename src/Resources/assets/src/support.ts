/**
 * Support tickets inside a support window (part `support`, under the messages). The requester's draft shows the
 * assistant: a greeting with the topics as buttons, or "continue ticket SUP-… or open a new one?", and their earlier
 * tickets. A ticket shows its number, topic and status, a Reopen button while the requester may reopen it and a note
 * when it is read-only. Every control is a real <button> in a labelled group (keyboard and screen readers).
 */
import type { ChatApi } from './api';
import { h } from './dom';
import { t } from './format';
import type { ChatConfig, ChatMessage, ConversationSummary, SupportIntake, SupportTicket } from './types';

/**
 * Readable number of a ticket ("" for a draft).
 *
 * @param ticket The ticket.
 * @returns The number.
 */
const num = (ticket: SupportTicket): string => ticket.number ?? '';

/** What the support panel needs from the window that hosts it (ThreadView). */
export interface SupportHost {
  config: ChatConfig;
  api: ChatApi;
  /**
   * The summary changed (ticket opened, reopened): refresh the window and the list.
   *
   * @param summary Updated summary.
   */
  conversationChanged(summary: ConversationSummary): void;
  /**
   * Show another conversation in place of this one (an earlier ticket).
   *
   * @param summary Summary of the conversation to open.
   */
  openConversation(summary: ConversationSummary): void;
  /**
   * Bot lines the server posted: add them to the thread.
   *
   * @param messages New messages.
   */
  appendMessages(messages: ChatMessage[]): void;
  /**
   * Show a short notice (errors).
   *
   * @param text Notice text.
   */
  notice(text: string): void;
}

/** The ticket panel of one support window. */
export class SupportPanel {
  private readonly host: SupportHost;
  private readonly element: HTMLElement;
  /** The requester answered "new ticket" to the resume question: show the topics. */
  private chooseNew = false;

  /**
   * Creates the panel.
   *
   * @param host Window hosting the panel.
   * @param element The `support` part of the window.
   */
  constructor(host: SupportHost, element: HTMLElement) {
    this.host = host;
    this.element = element;
  }

  /**
   * Renders the panel for a summary (hidden for other kinds and for agents looking at someone's draft).
   *
   * @param summary Current summary.
   */
  render(summary: ConversationSummary): void {
    const ticket = summary.kind === 'support' ? (summary.ticket ?? null) : null;
    const nodes: HTMLElement[] = [];
    if (ticket !== null && ticket.number === null && summary.intake !== undefined) {
      nodes.push(...this.intake(summary, summary.intake));
    } else if (ticket !== null && ticket.number !== null) {
      nodes.push(...this.ticket(ticket));
    }
    this.element.replaceChildren(...nodes);
    this.element.hidden = nodes.length === 0;
  }

  /**
   * Assistant bubble and earlier tickets of the requester's draft.
   *
   * @param summary Summary of the draft.
   * @param intake Intake data.
   * @returns The nodes.
   */
  private intake(summary: ConversationSummary, intake: SupportIntake): HTMLElement[] {
    const i18n = this.host.config.i18n;
    const draft = summary.uuid;
    const bubble = h('div', { class: 'nowo-chat-support__bot', 'data-testid': 'nowo-chat-support-intake' }, h('p', { class: 'nowo-chat-support__author', text: t(i18n, 'support_bot') }));
    const resume = intake.prompt === 'resume' && !this.chooseNew ? intake.resume : null;
    if (resume !== null) {
      const question = t(i18n, 'support_resume_question', { number: num(resume), topic: resume.topicLabel, status: resume.statusLabel, date: this.date(resume.lastMessageAt) });
      bubble.append(
        h('p', { text: question }),
        this.group(question, [
          this.button(t(i18n, 'support_continue', { number: num(resume) }), 'nowo-chat-support__primary', () => this.resume(resume.uuid, draft)),
          this.button(t(i18n, 'support_new_ticket'), '', () => {
            this.chooseNew = true;
            this.render(summary);
            this.element.querySelector<HTMLButtonElement>('[data-topic]')?.focus();
          }),
        ]),
      );
    } else {
      bubble.append(
        h('p', { text: t(i18n, 'support_greeting') }),
        this.group(
          t(i18n, 'support_topics_label'),
          intake.topics.map((topic) => {
            const button = this.button(topic.label, 'nowo-chat-support__topic', () => this.choose(draft, topic.key));
            button.dataset.topic = topic.key;
            return button;
          }),
        ),
      );
    }
    const nodes: HTMLElement[] = [bubble];
    if (intake.tickets.length > 0) {
      const list = h('ul', { class: 'nowo-chat-support__tickets', role: 'list' });
      for (const ticket of intake.tickets) {
        const label = `${t(i18n, 'support_ticket_label', { number: num(ticket), topic: ticket.topicLabel, status: ticket.statusLabel })} · ${this.date(ticket.lastMessageAt)}`;
        list.append(h('li', {}, this.button(label, 'nowo-chat-support__ticket-link', () => this.openTicket(ticket.uuid))));
      }
      nodes.push(h('section', { class: 'nowo-chat-support__previous', 'aria-label': t(i18n, 'support_previous_tickets') }, h('p', { class: 'nowo-chat-support__heading', text: t(i18n, 'support_previous_tickets') }), list));
    }
    return nodes;
  }

  /**
   * Status line of a ticket, Reopen for the requester, and the read-only note.
   *
   * @param ticket The ticket.
   * @returns The nodes.
   */
  private ticket(ticket: SupportTicket): HTMLElement[] {
    const i18n = this.host.config.i18n;
    const line = h('p', { class: `nowo-chat-support__status is-${ticket.status}`, 'data-testid': 'nowo-chat-support-status' }, t(i18n, 'support_ticket_label', { number: num(ticket), topic: ticket.topicLabel, status: ticket.statusLabel }));
    const nodes: HTMLElement[] = [line];
    const requester = ticket.requester?.uuid === this.host.config.userUuid;
    if (ticket.readOnly) {
      nodes.push(h('p', { class: 'nowo-chat-support__note', text: t(i18n, 'support_read_only_ticket') }));
    } else if (requester && ticket.status === 'resolved') {
      nodes.push(this.group(t(i18n, 'support_reopen'), [this.button(t(i18n, 'support_reopen'), 'nowo-chat-support__primary', () => this.reopen(ticket.uuid))]));
    }
    return nodes;
  }

  private async choose(draft: string, topic: string): Promise<void> {
    await this.run(async () => {
      const { conversation, messages } = await this.host.api.chooseTopic(draft, topic);
      this.host.appendMessages(messages);
      this.host.conversationChanged(conversation);
    });
  }

  private async resume(ticket: string, draft: string): Promise<void> {
    await this.run(async () => this.host.openConversation(await this.host.api.resumeTicket(ticket, draft)));
  }

  private async reopen(ticket: string): Promise<void> {
    await this.run(async () => this.host.conversationChanged(await this.host.api.setTicketStatus(ticket, 'open')));
  }

  private async openTicket(ticket: string): Promise<void> {
    await this.run(async () => this.host.openConversation(await this.host.api.conversation(ticket)));
  }

  /**
   * Runs one action with the buttons disabled (so a second click cannot start another); a failure shows the error
   * notice and re-enables them.
   *
   * @param action The action.
   */
  private async run(action: () => Promise<void>): Promise<void> {
    this.setDisabled(true);
    try {
      await action();
    } catch {
      this.host.notice(t(this.host.config.i18n, 'error'));
    } finally {
      this.setDisabled(false);
    }
  }

  private setDisabled(disabled: boolean): void {
    for (const button of this.element.querySelectorAll('button')) {
      button.disabled = disabled;
    }
  }

  private group(label: string, buttons: HTMLButtonElement[]): HTMLElement {
    return h('div', { class: 'nowo-chat-support__actions', role: 'group', 'aria-label': label }, ...buttons);
  }

  private button(label: string, className: string, onClick: () => void): HTMLButtonElement {
    const button = h('button', { type: 'button', class: `nowo-chat-support__btn ${className}`.trim(), text: label });
    button.addEventListener('click', onClick);
    return button;
  }

  private date(iso: string): string {
    return new Intl.DateTimeFormat(this.host.config.locale, { dateStyle: 'medium' }).format(new Date(iso));
  }
}
