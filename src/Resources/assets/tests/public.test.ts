import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import * as prefs from '../src/prefs';
import * as entry from '../src/public';
import type { ChatPrefs, SoundName } from '../src/public';
import * as sounds from '../src/sounds';

// The entry must not pull the dock in: a settings screen ships without it.
vi.mock('../src/dock', () => {
  throw new Error('public.ts loaded the dock');
});
vi.mock('../src/api', () => {
  throw new Error('public.ts loaded the API client');
});

describe('public entry', () => {
  it('exports the preferences and sounds, and nothing else', () => {
    expect(Object.keys(entry).sort()).toEqual(['PREFS_EVENT', 'SOUND_NAMES', 'playSound', 'saveChatPreference']);
    expect(entry.saveChatPreference).toBe(prefs.saveChatPreference);
    expect(entry.PREFS_EVENT).toBe(prefs.PREFS_EVENT);
    expect(entry.playSound).toBe(sounds.playSound);
    expect(entry.SOUND_NAMES).toBe(sounds.SOUND_NAMES);
  });

  it('exports the types a host needs', () => {
    const sound: SoundName = entry.SOUND_NAMES[0] as SoundName;
    const saved: Partial<ChatPrefs> = {};
    expect(typeof sound).toBe('string');
    expect(saved).toEqual({});
  });

  it('is the entry the bundle docs name', () => {
    const usage = readFileSync(resolve(__dirname, '../../../../docs/USAGE.md'), 'utf8');
    expect(usage).toContain('src/Resources/assets/src/public');
  });
});
