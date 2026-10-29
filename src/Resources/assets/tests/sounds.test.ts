import { afterEach, describe, expect, it, vi } from 'vitest';

function fakeAudio(state: 'running' | 'suspended') {
  const started: number[] = [];
  const node = () => ({ connect: vi.fn().mockReturnThis(), gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() } });
  class FakeContext {
    state = state;
    currentTime = 0;
    destination = {};
    resume = vi.fn().mockRejectedValue(new Error('gesture needed'));
    createOscillator() {
      return { type: 'sine', frequency: { setValueAtTime: vi.fn() }, connect: vi.fn().mockReturnValue(node()), start: (at: number) => started.push(at), stop: vi.fn() };
    }
    createGain() {
      return node();
    }
  }
  return { FakeContext, started };
}

describe('chat sounds', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
    window.localStorage.clear();
  });

  it('synthesises each sound and resumes a suspended context', async () => {
    const { FakeContext, started } = fakeAudio('suspended');
    vi.stubGlobal('AudioContext', FakeContext);
    const { playSound } = await import('../src/sounds');
    playSound('none');
    expect(started).toHaveLength(0);
    playSound('pop');
    playSound('chime');
    playSound('ding');
    playSound('buzz');
    expect(started).toHaveLength(1 + 2 + 1 + 3);
    // 1.10: three more sounds, a volume (0 = silent) and unknown names are ignored.
    playSound('bell', 50);
    playSound('blip', 150);
    playSound('knock', -5);
    playSound('foghorn' as never);
    expect(started).toHaveLength(7 + 3 + 2);
  });

  it('is silent without Web Audio and uses the webkit prefix when present', async () => {
    vi.stubGlobal('AudioContext', undefined);
    const { playSound } = await import('../src/sounds');
    expect(() => playSound('pop')).not.toThrow();

    vi.resetModules();
    const { FakeContext, started } = fakeAudio('running');
    vi.stubGlobal('webkitAudioContext', FakeContext);
    const fresh = await import('../src/sounds');
    fresh.playSound('ding');
    expect(started).toHaveLength(1);
  });

  it('plays a given event once across tabs', async () => {
    vi.useFakeTimers();
    const { FakeContext, started } = fakeAudio('running');
    vi.stubGlobal('AudioContext', FakeContext);
    const { playOnce } = await import('../src/sounds');
    playOnce('msg-1', 'pop');
    playOnce('msg-1', 'pop');
    expect(started).toHaveLength(1);
    vi.advanceTimersByTime(10_000);
    expect(window.localStorage.getItem('nowo-chat-sound:msg-1')).toBeNull();

    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    playOnce('msg-2', 'pop');
    expect(started).toHaveLength(2);
    spy.mockRestore();
    vi.useRealTimers();
  });
});
