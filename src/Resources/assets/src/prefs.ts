/**
 * Chat preferences outside the dock (1.10): an application's settings screen saves one preference at a time through
 * the bundle API (`POST nowo_chat_api_settings {key, value}` with the `nowo_chat` CSRF header token) and tells the
 * dock of the page, which applies it at once (`nowo-chat:prefs` event). The user's other tabs follow through the
 * realtime `prefs` event.
 */
import type { ChatPrefs } from './types';

/** Event the dock listens to for preferences changed on the page (detail: every preference). */
export const PREFS_EVENT = 'nowo-chat:prefs';

/**
 * Saves one chat preference and tells the dock of the page.
 *
 * @param endpoint URL of `nowo_chat_api_settings`.
 * @param csrf Token of the `nowo_chat` CSRF id.
 * @param key Preference key (ChatUserSettings::PREFERENCE_KEYS).
 * @param value Its new value.
 * @returns Every preference after the change.
 * @throws Error When the server refuses the change (unknown key, invalid value, CSRF, rate limit).
 */
export async function saveChatPreference(endpoint: string, csrf: string, key: string, value: string | number | boolean): Promise<ChatPrefs> {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ key, value }),
  });
  if (!response.ok) {
    throw new Error(`nowo-chat settings: ${response.status}`);
  }
  const { prefs } = (await response.json()) as { prefs: ChatPrefs };
  document.dispatchEvent(new CustomEvent<ChatPrefs>(PREFS_EVENT, { detail: prefs }));
  return prefs;
}
