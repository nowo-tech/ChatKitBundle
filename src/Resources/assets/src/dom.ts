/**
 * DOM helpers for the dock (no framework; text always goes through textContent). The markup comes from the
 * Twig <template> elements (Templates); h() only builds small dynamic pieces (badges, chips, avatars).
 */

type Child = Node | string | null | undefined | false;

/**
 * Builds an element: `class` sets the class name, `text` the text content, other attributes are set as given
 * (true → empty attribute; null, undefined and false are skipped). String children become text nodes.
 *
 * @param tag Tag name.
 * @param attrs Attributes (plus the `class` and `text` shortcuts).
 * @param children Child nodes or strings; null, undefined and false are skipped.
 * @returns The new element.
 */
export function h<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Record<string, string | number | boolean | null | undefined> = {},
  ...children: Child[]
): HTMLElementTagNameMap[K] {
  const element = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) {
      continue;
    }
    if (name === 'class') {
      element.className = String(value);
    } else if (name === 'text') {
      element.textContent = String(value);
    } else {
      element.setAttribute(name, value === true ? '' : String(value));
    }
  }
  for (const child of children) {
    if (child !== null && child !== undefined && child !== false) {
      element.append(typeof child === 'string' ? document.createTextNode(child) : child);
    }
  }
  return element;
}

/** Built-in icons (24×24, stroke). `icon_set: ux_icon` replaces them with the application's UX Icons. */
const ICONS: Record<string, string> = {
  chat: '<path d="M4 5h16v11H8l-4 4V5Z" />',
  compose: '<path d="M4 20h4L19 9l-4-4L4 16v4Z" /><path d="m13.5 6.5 4 4" />',
  chevronUp: '<path d="m6 15 6-6 6 6" />',
  chevronDown: '<path d="m6 9 6 6 6-6" />',
  close: '<path d="M6 6l12 12M18 6 6 18" />',
  tuck: '<path d="M4 12h10M10 8l4 4-4 4M20 4v16" />',
  send: '<path d="M4 12 20 4l-6 16-3-7-7-1Z" />',
  back: '<path d="M15 6 9 12l6 6" />',
  arrowDown: '<path d="M12 5v14M6 13l6 6 6-6" />',
  search: '<circle cx="11" cy="11" r="6" /><path d="m20 20-4.5-4.5" />',
  hash: '<path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16" />',
  lifebuoy: '<circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3.5" /><path d="m6.3 6.3 3.2 3.2M14.5 14.5l3.2 3.2M17.7 6.3l-3.2 3.2M9.5 14.5l-3.2 3.2" />',
  retry: '<path d="M20 12a8 8 0 1 1-2.3-5.7" /><path d="M20 4v5h-5" />',
  buzz: '<path d="M13 3 5 14h6l-1 7 8-11h-6l1-7Z" />',
  smile: '<circle cx="12" cy="12" r="8.5" /><path d="M8.5 14.5c1 1.2 2.1 1.8 3.5 1.8s2.5-.6 3.5-1.8" /><path d="M9 10h.01M15 10h.01" />',
  check: '<path d="m5 12 4.5 4.5L19 7" />',
  archive: '<rect x="4" y="4" width="16" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" />',
  unarchive: '<path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" />',
  pin: '<path d="m15 4.5-4 4-4 1.5-1.5 1.5 7 7 1.5-1.5 1.5-4 4-4" /><path d="M9 15l-4.5 4.5M14.5 4 20 9.5" />',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16v4Z" />',
  trash: '<path d="M5 7h14M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3" />',
  ban: '<circle cx="12" cy="12" r="8.5" /><path d="m6 6 12 12" />',
  external: '<path d="M14 4h6v6" /><path d="M20 4 11 13" /><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />',
  link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />',
  // #reference cards and `/` commands.
  lock: '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />',
  issue: '<path d="M9 9V7a3 3 0 0 1 6 0v2" /><rect x="7" y="9" width="10" height="11" rx="5" /><path d="M4 13h3M17 13h3M5 19l2.5-2M19 19l-2.5-2M12 12v5" />',
  task: '<rect x="4" y="4" width="16" height="16" rx="3" /><path d="m8.5 12 2.5 2.5 4.5-5" />',
  project: '<path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z" />',
  monitor: '<path d="M3 12h4l3-7 4 14 3-7h4" />',
  report: '<path d="M6 3h9l4 4v14H6V3Z" /><path d="M14 3v5h5M9 17v-3M12 17v-6M15 17v-2" />',
  ticket: '<path d="M4 8a2 2 0 0 0 0 4v4h16v-4a2 2 0 0 1 0-4V4H4v4Z" /><path d="M10 4v12" />',
  page: '<path d="M6 3h9l4 4v14H6V3Z" /><path d="M14 3v5h5M9 12h6M9 16h6" />',
};

/**
 * Decorative inline SVG (aria-hidden) from the built-in set; the accessible name lives on the button.
 *
 * @param name Icon name (an unknown name gives an empty SVG).
 * @param size Width and height in pixels.
 * @returns The SVG element.
 */
export function builtinIcon(name: string, size = 18): SVGSVGElement {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  for (const [attribute, value] of Object.entries({
    viewBox: '0 0 24 24',
    width: String(size),
    height: String(size),
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': '1.8',
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round',
    'aria-hidden': 'true',
    focusable: 'false',
  })) {
    svg.setAttribute(attribute, value);
  }
  // eslint-disable-next-line no-restricted-syntax -- fixed SVG path strings from the ICONS map above
  svg.innerHTML = ICONS[name] ?? '';
  return svg;
}

/**
 * Whether the user asked the system for reduced motion.
 *
 * @returns True when `prefers-reduced-motion: reduce` matches (false without matchMedia).
 */
export function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Unread badge: the visible number is decorative; screen readers get the sentence ("Unread messages: 3")
 * as text — an aria-label on a generic span is not reliably announced.
 *
 * @param badge Badge element (hidden when the count is zero).
 * @param count Unread count ("99+" above 99).
 * @param label Sentence for screen readers.
 */
export function setBadge(badge: HTMLElement, count: number, label: string): void {
  badge.hidden = count <= 0;
  badge.replaceChildren(h('span', { 'aria-hidden': 'true', text: count > 99 ? '99+' : String(count) }), h('span', { class: 'nowo-chat-sr-only', text: label }));
}
