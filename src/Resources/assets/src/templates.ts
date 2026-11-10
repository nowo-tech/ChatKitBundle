/**
 * The dock's markup contract: Twig renders <template data-nowo-chat-template="…"> elements (customisable by the
 * application); the script clones them and fills their `data-part` hooks. Icons: `data-icon` spans get the
 * application's UX Icons (<template data-nowo-chat-icon>) or the built-in SVG, unless they already hold one.
 */
import { attachAvatarImage } from './avatar';
import { builtinIcon, h, hasBuiltinIcon } from './dom';
import type { VisibleStatus } from './types';

/** Serial suffix that keeps the ids of cloned form controls unique (several windows clone the same template). */
let serial = 0;

/** Clones the Twig `<template>` markup of the dock and resolves its icons. */
export class Templates {
  private readonly root: ParentNode;

  /**
   * Creates the template source.
   *
   * @param root Element (or document) that holds the `<template>` elements.
   */
  constructor(root: ParentNode) {
    this.root = root;
  }

  /**
   * A fresh copy of the template's first element, icons filled in and form control ids made unique.
   *
   * @param name Value of `data-nowo-chat-template`.
   * @returns The cloned element.
   * @throws Error When the template is missing or has no element.
   */
  clone<T extends HTMLElement = HTMLElement>(name: string): T {
    const template = this.root.querySelector<HTMLTemplateElement>(`template[data-nowo-chat-template="${name}"]`);
    const element = template?.content.firstElementChild?.cloneNode(true);
    if (!(element instanceof HTMLElement)) {
      throw new Error(`NowoChat: missing <template data-nowo-chat-template="${name}">.`);
    }
    this.fillIcons(element);
    Templates.uniqueIds(element);
    return element as T;
  }

  /**
   * Icon by name: the application's (ux_icon) when provided, else the built-in SVG; when neither exists and a
   * fallback is given (e.g. `provider_teams` → `video`), the fallback icon.
   *
   * @param name Icon name.
   * @param size Width and height in pixels.
   * @param fallback Icon used when the application and the built-in set have none named `name`.
   * @returns The icon element (aria-hidden).
   */
  icon(name: string, size = 18, fallback?: string | null): Element {
    const custom = this.root.querySelector<HTMLTemplateElement>(`template[data-nowo-chat-icon="${name}"]`)?.content.firstElementChild;
    if (custom instanceof Element) {
      const copy = custom.cloneNode(true) as Element;
      copy.setAttribute('width', String(size));
      copy.setAttribute('height', String(size));
      copy.setAttribute('aria-hidden', 'true');
      return copy;
    }
    if (!hasBuiltinIcon(name) && fallback !== undefined && fallback !== null && fallback !== '' && fallback !== name) {
      return this.icon(fallback, size);
    }
    return builtinIcon(name, size);
  }

  /**
   * Avatar bubble: initials for people, an icon for channels and the support inbox.
   *
   * @param initials Initials shown for a person; for a channel (1.24) its icon text (`#`: the built-in icon); ignored for support.
   * @param kind Conversation kind.
   * @param size Avatar size.
   * @param online Adds the online dot.
   * @param status 1.10: the person's status (busy, away, dnd colour the dot; offline hides it); wins over `online`.
   * @param imageUrl 1.16: the person's picture (people only), over the initials; without it the bubble is unchanged.
   * @returns The avatar element (aria-hidden).
   */
  avatar(initials: string, kind: 'direct' | 'channel' | 'support' = 'direct', size: 'sm' | 'md' = 'md', online = false, status?: VisibleStatus, imageUrl?: string | null): HTMLElement {
    const shown = status === undefined ? online : status !== 'offline';
    const tone = status === undefined || status === 'offline' ? '' : ` is-status-${status}`;
    const element = h('span', { class: `nowo-chat-avatar nowo-chat-avatar--${kind} nowo-chat-avatar--${size}${shown ? ' is-online' : ''}${tone}`, 'aria-hidden': 'true' });
    if (kind === 'direct') {
      element.textContent = initials;
      attachAvatarImage(element, imageUrl, size === 'sm' ? 28 : 40);
    } else if (kind === 'channel' && initials !== '' && initials !== '#') {
      // 1.24: the channel's own icon (a short text such as an emoji), always as text.
      element.textContent = initials;
    } else {
      element.append(this.icon(kind === 'channel' ? 'hash' : 'lifebuoy', size === 'sm' ? 14 : 16));
    }
    return element;
  }

  /**
   * Gives every form control of the copy (Symfony Form widgets carry an `id`) a unique id, and moves the copy's
   * `<label for>` along, so several copies of the same template never share an id.
   *
   * @param scope Cloned element.
   */
  private static uniqueIds(scope: Element): void {
    scope.querySelectorAll<HTMLElement>('input[id], textarea[id], select[id]').forEach((control) => {
      serial += 1;
      const unique = `${control.id}-${serial}`;
      scope.querySelectorAll<HTMLLabelElement>('label[for]').forEach((label) => {
        if (label.htmlFor === control.id) {
          label.htmlFor = unique;
        }
      });
      control.id = unique;
    });
  }

  /**
   * Fills every empty `data-icon` slot of a cloned template or a rendered message (`data-icon-size`, default 18,
   * `data-icon-fallback` when the icon does not exist); slots that already hold markup are kept.
   *
   * @param scope Cloned element or message body.
   */
  fillIcons(scope: Element): void {
    scope.querySelectorAll<HTMLElement>('[data-icon]').forEach((slot) => {
      if (slot.childElementCount === 0) {
        slot.replaceChildren(this.icon(slot.dataset.icon ?? '', Number(slot.dataset.iconSize ?? 18), slot.dataset.iconFallback));
      }
    });
  }
}

/**
 * Required hook of a cloned template.
 *
 * @param scope Cloned element (matches itself too).
 * @param name Value of `data-part`.
 * @returns The hook element.
 * @throws Error When the template has no such hook.
 */
export function part<T extends HTMLElement = HTMLElement>(scope: Element, name: string): T {
  const element = scope.matches(`[data-part="${name}"]`) ? scope : scope.querySelector(`[data-part="${name}"]`);
  if (!(element instanceof HTMLElement)) {
    throw new Error(`NowoChat: the template has no [data-part="${name}"].`);
  }
  return element as T;
}

/**
 * Sets a hook's text and hides it when empty.
 *
 * @param scope Cloned element.
 * @param name Value of `data-part`.
 * @param value Text to show; null or "" hides the hook.
 * @returns The hook element.
 */
export function text(scope: Element, name: string, value: string | null): HTMLElement {
  const element = part(scope, name);
  element.textContent = value ?? '';
  element.hidden = value === null || value === '';
  return element;
}
