import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatApi } from '../src/api';
import { ChatRealtime } from '../src/realtime';

class FakeSource {
  static last: FakeSource | null = null;
  onopen: (() => void) | null = null;
  onmessage: ((event: MessageEvent<string>) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;
  readonly url: string;
  constructor(url: string) {
    this.url = url;
    FakeSource.last = this;
  }
  close() {
    this.closed = true;
  }
}

describe('chat realtime client', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('EventSource', FakeSource);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('subscribes to the own topic, forwards events, reconnects and refreshes the token', async () => {
    const realtime = vi.fn().mockResolvedValue({ enabled: true, hubUrl: '/.well-known/mercure', token: 'jwt', topic: '/users/u/chat', expiresAt: new Date(Date.now() + 3_600_000).toISOString() });
    const events = vi.fn();
    const states = vi.fn();
    const client = new ChatRealtime({ realtime } as unknown as ChatApi, events, states);

    await client.connect();
    const source = FakeSource.last as FakeSource;
    expect(source.url).toContain('topic=%2Fusers%2Fu%2Fchat');
    expect(source.url).toContain('authorization=jwt');
    source.onopen?.();
    expect(states).toHaveBeenLastCalledWith(true);
    source.onmessage?.({ data: '{"type":"typing","conversationUuid":"c"}' } as MessageEvent<string>);
    source.onmessage?.({ data: '{broken' } as MessageEvent<string>);
    expect(events).toHaveBeenCalledTimes(1);

    client.stop();
    source.onerror?.();
    expect(realtime).toHaveBeenCalledTimes(1);
    // @ts-expect-error reopen the same client for the reconnect scenario
    client.stopped = false;
    source.onerror?.();
    expect(states).toHaveBeenLastCalledWith(false);
    expect(source.closed).toBe(true);
    await vi.advanceTimersByTimeAsync(2_100);
    expect(realtime).toHaveBeenCalledTimes(2);

    // Token refresh five minutes before expiry.
    await vi.advanceTimersByTimeAsync(3_300_000);
    expect(realtime).toHaveBeenCalledTimes(3);
    client.stop();
  });

  it('stays on polling when realtime is disabled and retries after a failed config request', async () => {
    const states = vi.fn();
    const disabled = new ChatRealtime({ realtime: vi.fn().mockResolvedValue({ enabled: false, hubUrl: null, token: null, topic: 't', expiresAt: '' }) } as unknown as ChatApi, vi.fn(), states);
    await disabled.connect();
    expect(states).toHaveBeenCalledWith(false);

    vi.spyOn(Math, 'random').mockReturnValue(0);
    const realtime = vi.fn().mockRejectedValue(new Error('offline'));
    const failing = new ChatRealtime({ realtime } as unknown as ChatApi, vi.fn(), vi.fn());
    await failing.connect();
    await vi.advanceTimersByTimeAsync(1_000);
    expect(realtime).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(2_000);
    expect(realtime).toHaveBeenCalledTimes(3);
    failing.stop();
    await vi.advanceTimersByTimeAsync(120_000);
    expect(realtime).toHaveBeenCalledTimes(3);
    await failing.connect();
    expect(realtime).toHaveBeenCalledTimes(3);

    // Stopped while the config request is in flight: never opens a stream.
    let resolve: (value: unknown) => void = () => undefined;
    const late = new ChatRealtime({ realtime: () => new Promise((r) => (resolve = r)) } as unknown as ChatApi, vi.fn(), vi.fn());
    FakeSource.last = null;
    const pending = late.connect();
    late.stop();
    resolve({ enabled: true, hubUrl: '/hub', token: 't', topic: 'x', expiresAt: new Date().toISOString() });
    await pending;
    expect(FakeSource.last).toBeNull();
    vi.restoreAllMocks();
  });
});
