/**
 * The dock's markup contract: Twig renders <template data-nowo-chat-template="…"> elements (customisable by the
 * application); the script clones them and fills their `data-part` hooks. Icons: `data-icon` spans get the
 * application's UX Icons (<template data-nowo-chat-icon>) or the built-in SVG, unless they already hold one.
 */
import { builtinIcon, h } from './dom';

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
   * A fresh copy of the template's first element, icons filled in.
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
    return element as T;
  }

  /**
   * Icon by name: the application's (ux_icon) when provided, else the built-in SVG.
   *
   * @param name Icon name.
   * @param size Width and height in pixels.
   * @returns The icon element (aria-hidden).
   */
  icon(name: string, size = 18): Element {
    const custom = this.root.querySelector<HTMLTemplateElement>(`template[data-nowo-chat-icon="${name}"]`)?.content.firstElementChild;
    if (custom instanceof Element) {
      const copy = custom.cloneNode(true) as Element;
      copy.setAttribute('width', String(size));
      copy.setAttribute('height', String(size));
      copy.setAttribute('aria-hidden', 'true');
      return copy;
    }
    return builtinIcon(name, size);
  }

  /**
   * Avatar bubble: initials for people, an icon for channels and the support inbox.
   *
   * @param initials Initials shown for a person (ignored for channels and support).
   * @param kind Conversation kind.
   * @param size Avatar size.
   * @param online Adds the online dot.
   * @returns The avatar element (aria-hidden).
   */
  avatar(initials: string, kind: 'direct' | 'channel' | 'support' = 'direct', size: 'sm' | 'md' = 'md', online = false): HTMLElement {
    const element = h('span', { class: `nowo-chat-avatar nowo-chat-avatar--${kind} nowo-chat-avatar--${size}${online ? ' is-online' : ''}`, 'aria-hidden': 'true' });
    if (kind === 'direct') {
      element.textContent = initials;
    } else {
      element.append(this.icon(kind === 'channel' ? 'hash' : 'lifebuoy', size === 'sm' ? 14 : 16));
    }
    return element;
  }

  /**
   * Fills every empty `data-icon` slot of a cloned template (`data-icon-size`, default 18); slots that already hold markup are kept.
   *
   * @param scope Cloned element.
   */
  private fillIcons(scope: Element): void {
    scope.querySelectorAll<HTMLElement>('[data-icon]').forEach((slot) => {
      if (slot.childElementCount === 0) {
        slot.replaceChildren(this.icon(slot.dataset.icon ?? '', Number(slot.dataset.iconSize ?? 18)));
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
