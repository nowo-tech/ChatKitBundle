/**
 * Nowo Chat Kit — browser entry point (skeleton).
 *
 * The floating chat dock (Stimulus controllers prefixed `nowo-chat`, realtime client,
 * presence, typing, read receipts, sounds, emoji picker and Web Push) is migrated here
 * in later iterations. For now the entry only exposes the package version and the
 * CSS custom-property prefix shared with the Twig templates.
 */

/** Package version of the browser bundle. */
export const VERSION = '1.0.0-dev';

/** Prefix used by every CSS custom property (theme token) of the dock. */
export const CSS_TOKEN_PREFIX = '--nowo-chat-';

/**
 * Builds the CSS custom-property name for a theme token.
 *
 * @param token - Token name in kebab-case, e.g. `accent` or `bubble-radius`.
 * @returns The custom-property name, e.g. `--nowo-chat-accent`.
 */
export function cssToken(token: string): string {
  const normalized = token.trim().replace(/^-+/, '');
  if (normalized === '') {
    throw new Error('Theme token name must not be empty.');
  }

  return `${CSS_TOKEN_PREFIX}${normalized}`;
}
