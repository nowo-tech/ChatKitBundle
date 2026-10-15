/**
 * The dock's markup contract: Twig renders <template data-nowo-chat-template="…"> elements (customisable by the
 * application); the script clones them and fills their `data-part` hooks. Icons: `data-icon` spans get the
 * application's UX Icons (<template data-nowo-chat-icon>) or the built-in SVG, unless they already hold one.
 */
import { builtinIcon, h } from './dom';

export class Templates {
  private readonly root: ParentNode;

  constructor(root: ParentNode) {
    this.root = root;
  }

  /** A fresh copy of the template's first element, icons filled in. */
  clone<T extends HTMLElement = HTMLElement>(name: string): T {
    const template = this.root.querySelector<HTMLTemplateElement>(`template[data-nowo-chat-template="${name}"]`);
    const element = template?.content.firstElementChild?.cloneNode(true);
    if (!(element instanceof HTMLElement)) {
      throw new Error(`NowoChat: missing <template data-nowo-chat-template="${name}">.`);
    }
    this.fillIcons(element);
    return element as T;
  }

  /** Icon by name: the application's (ux_icon) when provided, else the built-in SVG. */
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

  /** Avatar bubble: initials for people, an icon for channels and the support inbox. */
  avatar(initials: string, kind: 'direct' | 'channel' | 'support' = 'direct', size: 'sm' | 'md' = 'md', online = false): HTMLElement {
    const element = h('span', { class: `nowo-chat-avatar nowo-chat-avatar--${kind} nowo-chat-avatar--${size}${online ? ' is-online' : ''}`, 'aria-hidden': 'true' });
    if (kind === 'direct') {
      element.textContent = initials;
    } else {
      element.append(this.icon(kind === 'channel' ? 'hash' : 'lifebuoy', size === 'sm' ? 14 : 16));
    }
    return element;
  }

  private fillIcons(scope: Element): void {
    scope.querySelectorAll<HTMLElement>('[data-icon]').forEach((slot) => {
      if (slot.childElementCount === 0) {
        slot.replaceChildren(this.icon(slot.dataset.icon ?? '', Number(slot.dataset.iconSize ?? 18)));
      }
    });
  }
}

/** Required hook of a cloned template. */
export function part<T extends HTMLElement = HTMLElement>(scope: Element, name: string): T {
  const element = scope.matches(`[data-part="${name}"]`) ? scope : scope.querySelector(`[data-part="${name}"]`);
  if (!(element instanceof HTMLElement)) {
    throw new Error(`NowoChat: the template has no [data-part="${name}"].`);
  }
  return element as T;
}

/** Sets a hook's text and hides it when empty. */
export function text(scope: Element, name: string, value: string | null): HTMLElement {
  const element = part(scope, name);
  element.textContent = value ?? '';
  element.hidden = value === null || value === '';
  return element;
}
