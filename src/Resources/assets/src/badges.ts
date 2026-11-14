/**
 * 1.29 (Beacon spec 234): author badges — the pills the application shows next to a message author's name (e.g.
 * "Agent" for a service account). They come in the message JSON (`authorBadges`, also in realtime events) as plain
 * text with an allow-listed variant; this module checks them again and renders them with textContent only.
 */
import { h } from './dom';
import type { AuthorBadge, AuthorBadgeVariant } from './types';

/** The variants the server allows (ChatAuthorBadgeVariant); anything else is dropped. */
export const AUTHOR_BADGE_VARIANTS: readonly AuthorBadgeVariant[] = ['neutral', 'info', 'success', 'warning', 'agent'];

/** Longest label and title, as on the server (ChatAuthorBadge). */
const LABEL_MAX = 40;
const TITLE_MAX = 120;

/** Something with a `label` (the minimal shape checked before reading the other fields). */
type Candidate = { label?: unknown; variant?: unknown; title?: unknown };

/**
 * The valid badges of a message payload: a list of `{label, variant, title?}` with a non-empty string label of at most
 * 40 characters, an allow-listed variant and an optional string title of at most 120 characters. Anything else (an
 * older server without the field, a malformed entry) gives no badge.
 *
 * @param value The `authorBadges` field of a message.
 * @returns The badges to show.
 */
export function authorBadges(value: unknown): AuthorBadge[] {
  if (!Array.isArray(value)) {
    return [];
  }
  const badges: AuthorBadge[] = [];
  for (const entry of value as unknown[]) {
    if (typeof entry !== 'object' || entry === null) {
      continue;
    }
    const { label, variant, title } = entry as Candidate;
    if (typeof label !== 'string' || label.trim() === '' || [...label].length > LABEL_MAX) {
      continue;
    }
    if (typeof variant !== 'string' || !(AUTHOR_BADGE_VARIANTS as readonly string[]).includes(variant)) {
      continue;
    }
    const tooltip = typeof title === 'string' && title !== '' && [...title].length <= TITLE_MAX ? title : null;
    badges.push({ label, variant: variant as AuthorBadgeVariant, title: tooltip });
  }
  return badges;
}

/**
 * Renders badges after an author's name: each one from the application's `<template data-nowo-chat-template="author-badge">`
 * (its `[data-part="label"]`, or the element itself, gets the label) when the page has it, else a plain span. The
 * label and the title go through textContent and setAttribute, never innerHTML.
 *
 * @param target The author element (its existing text is kept).
 * @param badges The badges (authorBadges()).
 * @param root Element (or document) that holds the dock templates.
 */
export function appendAuthorBadges(target: HTMLElement, badges: AuthorBadge[], root: ParentNode): void {
  const template = root.querySelector<HTMLTemplateElement>('template[data-nowo-chat-template="author-badge"]');
  for (const badge of badges) {
    const copy = template?.content.firstElementChild?.cloneNode(true);
    const element = copy instanceof HTMLElement ? copy : h('span', { class: 'nowo-chat-author-badge' });
    element.classList.add('nowo-chat-author-badge', `nowo-chat-author-badge--${badge.variant}`);
    element.dataset.variant = badge.variant;
    const slot = element.querySelector<HTMLElement>('[data-part="label"]') ?? element;
    slot.textContent = badge.label;
    if (badge.title !== null) {
      element.setAttribute('title', badge.title);
    }
    target.append(' ', element);
  }
}
