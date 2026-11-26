/**
 * The shipped IIFE entry (`nowo-chat.js`) logs the house "script loaded" line exactly once when it is evaluated.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// REQ-ASSETS-005: every test starts with an empty once-guard registry.
beforeEach(() => {
  delete (globalThis as { __nowoScriptBannerNames?: unknown }).__nowoScriptBannerNames;
});

describe('iife entry', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  it('logs "script loaded" once, without a build id when none is injected', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

    await import('../src/iife');

    const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
    expect(lines).toHaveLength(1);
    expect(lines[0]).toContain('[chat-kit] script loaded');
    expect(lines[0]).not.toContain('build: ');
  });

  it('includes the injected build id', async () => {
    vi.stubGlobal('__CHAT_KIT_BUILD_TIME__', 'abc123def456');
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

    await import('../src/iife');

    const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
    expect(lines).toHaveLength(1);
    expect(lines[0]).toContain('[chat-kit] script loaded, build: ');
    expect(JSON.stringify(log.mock.calls)).toContain('abc123def456');
  });
});
