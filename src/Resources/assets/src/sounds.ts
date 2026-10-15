/**
 * chat sounds synthesised with Web Audio — no audio files, no CSP change. Browsers
 * only allow audio after a user gesture on the page; until then `play()` is a silent no-op.
 */
import type { SoundName } from './types';

type Note = { freq: number; at: number; length: number; type?: OscillatorType; gain?: number };

const SOUNDS: Record<Exclude<SoundName, 'none'> | 'buzz', Note[]> = {
  pop: [{ freq: 880, at: 0, length: 0.09, type: 'sine', gain: 0.22 }],
  chime: [
    { freq: 1046.5, at: 0, length: 0.32, type: 'sine', gain: 0.16 },
    { freq: 1568, at: 0.11, length: 0.42, type: 'sine', gain: 0.12 },
  ],
  ding: [{ freq: 1318.5, at: 0, length: 0.6, type: 'triangle', gain: 0.2 }],
  buzz: [
    { freq: 150, at: 0, length: 0.12, type: 'sawtooth', gain: 0.14 },
    { freq: 150, at: 0.17, length: 0.12, type: 'sawtooth', gain: 0.14 },
    { freq: 150, at: 0.34, length: 0.12, type: 'sawtooth', gain: 0.14 },
  ],
};

let context: AudioContext | null = null;

function audio(): AudioContext | null {
  const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (Ctor === undefined) {
    return null;
  }
  context ??= new Ctor();
  return context;
}

export function playSound(name: SoundName | 'buzz'): void {
  if (name === 'none') {
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
  for (const note of SOUNDS[name]) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = note.type ?? 'sine';
    osc.frequency.setValueAtTime(note.freq, start + note.at);
    gain.gain.setValueAtTime(0.0001, start + note.at);
    gain.gain.exponentialRampToValueAtTime(note.gain ?? 0.2, start + note.at + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + note.at + note.length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start + note.at);
    osc.stop(start + note.at + note.length + 0.02);
  }
}

/**
 * Several browser tabs receive the same event: only one of them plays it (first to claim the message id).
 */
export function playOnce(key: string, name: SoundName | 'buzz'): void {
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
  playSound(name);
}
