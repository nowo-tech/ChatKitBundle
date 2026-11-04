/**
 * Profile pictures (1.16): an `<img>` over the initials of a person's bubble. The server already filters the URLs
 * (AvatarUrlPolicy); the dock checks them again before it sets `src`. A picture that fails to load is removed and its
 * URL is remembered for the life of the page (never stored), so a 403 is not fetched again on every poll.
 */
import { h } from './dom';

/** Longest URL accepted, in UTF-8 bytes, as on the server. */
const MAX_LENGTH = 2048;

/** Never allowed anywhere: ASCII controls, space, DEL, backslash, quotes, backtick, angle brackets. */
// eslint-disable-next-line no-control-regex
const FORBIDDEN = /[\u0000-\u0020\u007f\\"'`<>]/;

/** A same-origin path (one leading slash) or https with a host of non-reserved characters and an optional port. */
const SHAPE = /^(?:\/(?![/\\])|https:\/\/[^/?#@[\]:]+(?::([0-9]{1,5}))?(?=[/?#]|$))/i;

/** URLs that failed to load on this page. */
const failed = new Set<string>();

/**
 * Whether a URL may become an avatar `src`, the same rule as the server's AvatarUrlPolicy: `https://host[:port]`
 * without user info, or a same-origin path starting with exactly one `/`. No other scheme (`http:`, `javascript:`,
 * `data:`, `blob:`…), no `//host`, no IPv6 literal, no port outside 1–65535, no backslash, quote, backtick, `<`, `>`,
 * whitespace or control character, at most 2048 UTF-8 bytes.
 *
 * @param url The URL from the API.
 * @returns True when it may be used.
 */
export function isSafeAvatarUrl(url: string | null | undefined): url is string {
  if (typeof url !== 'string' || url === '' || new TextEncoder().encode(url).length > MAX_LENGTH || FORBIDDEN.test(url)) {
    return false;
  }
  const match = SHAPE.exec(url);
  if (match === null) {
    return false;
  }
  const port = match[1];
  return port === undefined || (Number(port) >= 1 && Number(port) <= 65535);
}

/**
 * Puts the picture over the initials of `holder` (adds `has-image` and an `<img>` child). Nothing happens without a
 * usable URL, or for a URL that already failed on this page; on a load error the image goes and the initials show.
 *
 * @param holder The bubble (`.nowo-chat-avatar` or the mention option's initials).
 * @param url The picture URL from the API.
 * @param size Width and height attributes, in px (the bubble's size class).
 * @returns True when a picture was added.
 */
export function attachAvatarImage(holder: HTMLElement, url: string | null | undefined, size: number): boolean {
  if (!isSafeAvatarUrl(url) || failed.has(url)) {
    return false;
  }
  const image = h('img', {
    class: 'nowo-chat-avatar__image', alt: '', width: size, height: size, loading: 'lazy', decoding: 'async', referrerpolicy: 'same-origin',
  });
  image.addEventListener('error', () => {
    failed.add(url);
    image.remove();
    holder.classList.remove('has-image');
  }, { once: true });
  image.setAttribute('src', url);
  holder.classList.add('has-image');
  holder.append(image);
  return true;
}

/** Forgets the failed URLs (tests). */
export function forgetFailedAvatars(): void {
  failed.clear();
}
