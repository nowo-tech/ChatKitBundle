/**
 * Mercure subscription to the caller's own private topic. The subscriber token is short-lived
 * (1 h): the connection is re-opened with a fresh token before it expires and after errors (exponential
 * backoff). While disconnected the dock falls back to polling, so no event is ever lost.
 */
import type { ChatApi } from './api';
import type { RealtimeEvent } from './types';

export class ChatRealtime {
  private source: EventSource | null = null;
  private retry = 0;
  private refreshTimer: number | null = null;
  private retryTimer: number | null = null;
  private stopped = false;
  private readonly api: ChatApi;
  private readonly onEvent: (event: RealtimeEvent) => void;
  private readonly onState: (connected: boolean) => void;

  constructor(api: ChatApi, onEvent: (event: RealtimeEvent) => void, onState: (connected: boolean) => void) {
    this.api = api;
    this.onEvent = onEvent;
    this.onState = onState;
  }

  async connect(): Promise<void> {
    if (this.stopped) {
      return;
    }
    let config;
    try {
      config = await this.api.realtime();
    } catch {
      this.scheduleReconnect();
      return;
    }
    if (this.stopped) {
      return;
    }
    if (!config.enabled || config.hubUrl === null || config.token === null) {
      this.onState(false);
      return;
    }
    const url = new URL(config.hubUrl, window.location.href);
    url.searchParams.append('topic', config.topic);
    url.searchParams.set('authorization', config.token);
    this.close();
    const source = new EventSource(url.toString(), { withCredentials: true });
    this.source = source;
    source.onopen = () => {
      this.retry = 0;
      this.onState(true);
    };
    source.onmessage = (message: MessageEvent<string>) => {
      try {
        this.onEvent(JSON.parse(message.data) as RealtimeEvent);
      } catch {
        // Ignore malformed frames.
      }
    };
    source.onerror = () => {
      this.onState(false);
      this.close();
      this.scheduleReconnect();
    };
    // Re-open with a fresh token five minutes before the current one expires.
    const refreshIn = Math.max(60_000, new Date(config.expiresAt).getTime() - Date.now() - 300_000);
    this.refreshTimer = window.setTimeout(() => void this.connect(), refreshIn);
  }

  /** Final: pending retries and token refreshes are cancelled and never reconnect. */
  stop(): void {
    this.stopped = true;
    this.close();
    if (this.retryTimer !== null) {
      window.clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
  }

  private scheduleReconnect(): void {
    if (this.stopped) {
      return;
    }
    const delay = Math.min(60_000, 1_000 * 2 ** this.retry) + Math.random() * 1_000;
    this.retry += 1;
    this.retryTimer = window.setTimeout(() => {
      this.retryTimer = null;
      void this.connect();
    }, delay);
  }

  private close(): void {
    if (this.refreshTimer !== null) {
      window.clearTimeout(this.refreshTimer);
      this.refreshTimer = null;
    }
    this.source?.close();
    this.source = null;
  }
}
