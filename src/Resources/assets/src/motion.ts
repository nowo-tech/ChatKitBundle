/**
 * Closing with an effect. Firefox does not transition `display`, and WebKit does not animate a top-layer close, so a CSS-only close is
 * not enough: `CLOSING_ATTR` starts the fade (`--nowo-ui-motion-*`, read with own fallbacks) and the real close runs when it ends, with a
 * timer in case `transitionend` never fires. With no transition (`0ms`, or a page without the bundle's CSS) it all happens at once.
 */
const SLACK_MS = 50;

export const CLOSING_ATTR = 'data-nowo-chat-closing';

const toMs = (value: string): number => {
  const number = Number.parseFloat(value);
  if (Number.isNaN(number)) {
    return 0;
  }
  return value.trim().endsWith('ms') ? number : number * 1000;
};

/** The longest transition of an element in its current state, in ms. */
export function effectMs(element: Element): number {
  const style = window.getComputedStyle(element);
  const durations = (style.transitionDuration ?? '').split(',');
  const delays = (style.transitionDelay ?? '').split(',');
  return durations.reduce((longest, duration, index) => Math.max(longest, toMs(duration) + toMs(delays[index % delays.length] as string)), 0);
}

const running = new Map<Element, () => void>();

let cacheBound = false;

/** Turbo clones the page right after this event: a cached page must not hold a half-closed surface. */
function finishAll(): void {
  [...running.values()].forEach((finish) => {
    finish();
  });
}

/** Whether an element is fading out. */
export function isClosing(element: Element): boolean {
  return running.has(element);
}

/** Starts the fade, then runs `close`. A second call during the fade does nothing. */
export function closeWithEffect(element: Element, close: () => void): void {
  if (running.has(element)) {
    return;
  }
  element.setAttribute(CLOSING_ATTR, '');
  const ms = effectMs(element);
  const timer = ms > 0 ? window.setTimeout(() => finish(), ms + SLACK_MS) : undefined;
  const onEnd = (event: Event): void => {
    if (event.target === element) {
      finish();
    }
  };
  const finish = (): void => {
    running.delete(element);
    window.clearTimeout(timer);
    element.removeEventListener('transitionend', onEnd);
    element.removeAttribute(CLOSING_ATTR);
    close();
  };
  if (ms <= 0) {
    finish();
    return;
  }
  running.set(element, finish);
  if (!cacheBound) {
    cacheBound = true;
    document.addEventListener('turbo:before-cache', finishAll);
  }
  element.addEventListener('transitionend', onEnd);
}

/** Ends a fade at once, for an element about to open again. */
export function finishClosing(element: Element): void {
  running.get(element)?.();
}
