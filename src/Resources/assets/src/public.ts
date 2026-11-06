/**
 * Public entry for a host's own bundler (1.21): chat preferences and sounds for an application's settings screen,
 * without the dock (only `prefs` and `sounds` load). This is the supported import; the other files of this folder are
 * internal and can change in any release. See docs/USAGE.md, "Front end: IIFE or your bundler".
 */
export { PREFS_EVENT, saveChatPreference } from './prefs';
export { playSound, SOUND_NAMES } from './sounds';
export type { ChatPrefs, SoundName } from './types';
