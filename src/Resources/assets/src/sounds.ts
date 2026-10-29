/**
 * chat sounds synthesised with Web Audio — no audio files, no CSP change. Browsers
 * only allow audio after a user gesture on the page; until then `play()` is a silent no-op.
 */
import type { SoundName } from './types';

type Note = { freq: number; at: number; length: number; type: OscillatorType; gain: number };

const SOUNDS: Record<Exclude<SoundName, 'none'>, Note[]> = {
  pop: [{ freq: 880, at: 0, length: 0.09, type: 'sine', gain: 0.22 }],
  chime: [
    { freq: 1046.5, at: 0, length: 0.32, type: 'sine', gain: 0.16 },
    { freq: 1568, at: 0.11, length: 0.42, type: 'sine', gain: 0.12 },
  ],
  ding: [{ freq: 1318.5, at: 0, length: 0.6, type: 'triangle', gain: 0.2 }],
  bell: [
    { freq: 784, at: 0, length: 0.9, type: 'sine', gain: 0.16 },
    { freq: 1568, at: 0, length: 0.5, type: 'sine', gain: 0.06 },
    { freq: 2352, at: 0, length: 0.3, type: 'sine', gain: 0.03 },
  ],
  blip: [
    { freq: 660, at: 0, length: 0.06, type: 'square', gain: 0.08 },
    { freq: 990, at: 0.07, length: 0.08, type: 'square', gain: 0.08 },
  ],
  knock: [
    { freq: 220, at: 0, length: 0.07, type: 'triangle', gain: 0.3 },
    { freq: 220, at: 0.16, length: 0.07, type: 'triangle', gain: 0.3 },
  ],
  buzz: [
    { freq: 150, at: 0, length: 0.12, type: 'sawtooth', gain: 0.14 },
    { freq: 150, at: 0.17, length: 0.12, type: 'sawtooth', gain: 0.14 },
    { freq: 150, at: 0.34, length: 0.12, type: 'sawtooth', gain: 0.14 },
  ],
};

let context: AudioContext | null = null;

/**
 * The shared AudioContext, created on first use.
 *
 * @returns The context, or null when the browser has no Web Audio.
 */
function audio(): AudioContext | null {
  const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (Ctor === undefined) {
    return null;
  }
  context ??= new Ctor();
  return context;
}

/** Every sound a user may pick, in menu order. */
export const SOUND_NAMES: readonly SoundName[] = ['none', 'pop', 'chime', 'ding', 'bell', 'blip', 'knock', 'buzz'];

/**
 * Plays a sound synthesised from its notes (oscillator + gain envelope); "none", a zero volume, unknown names and
 * browsers without Web Audio are a no-op.
 *
 * @param name Sound to play.
 * @param volume 0 – 100 (the user's chat volume); 100 = the sound's own level.
 */
export function playSound(name: SoundName, volume = 100): void {
  const notes = name === 'none' ? undefined : SOUNDS[name];
  const level = Math.min(100, Math.max(0, volume)) / 100;
  if (notes === undefined || level === 0) {
    return;
  }
  const ctx = audio();
  if (ctx === null) {
    return;
  }
  if (ctx.state === 'suspended') {
    void ctx.resume().catch(() => undefined);
  }
  const start = ctx.currentTime + 0.01;
  for (const note of notes) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = note.type;
    osc.frequency.setValueAtTime(note.freq, start + note.at);
    gain.gain.setValueAtTime(0.0001, start + note.at);
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, note.gain * level), start + note.at + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + note.at + note.length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start + note.at);
    osc.stop(start + note.at + note.length + 0.02);
  }
}

/**
 * Several browser tabs receive the same event: only one of them plays it (first to claim the message id).
 *
 * @param key Event key shared by the tabs (e.g. "msg-<id>"); claimed for 10 s.
 * @param name Sound to play.
 * @param volume 0 – 100.
 */
export function playOnce(key: string, name: SoundName, volume = 100): void {
  const lock = `nowo-chat-sound:${key}`;
  try {
    const claimed = Number(window.localStorage.getItem(lock));
    if (claimed > 0 && Date.now() - claimed < 10_000) {
      return;
    }
    window.localStorage.setItem(lock, String(Date.now()));
    window.setTimeout(() => window.localStorage.removeItem(lock), 10_000);
  } catch {
    // No storage: play anyway.
  }
  playSound(name, volume);
}
