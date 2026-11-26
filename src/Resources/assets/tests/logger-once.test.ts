/**
 * REQ-ASSETS-005: the "script loaded" line prints once per page, even when the script is loaded twice.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger } from '../src/logger';

type BannerHost = { __nowoScriptBannerNames?: unknown };

describe('script banner once-guard', () => {
  let log: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    delete (globalThis as BannerHost).__nowoScriptBannerNames;
    log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (globalThis as BannerHost).__nowoScriptBannerNames;
  });

  it('prints one banner when the script is loaded twice', () => {
    createBundleLogger('once-test').scriptLoaded();
    createBundleLogger('once-test', { buildTime: '2026-11-26 10:00 UTC' }).scriptLoaded();

    expect(log).toHaveBeenCalledTimes(1);
  });

  it('prints each logger name once', () => {
    createBundleLogger('once-a').scriptLoaded();
    createBundleLogger('once-b').scriptLoaded();
    createBundleLogger('once-a').scriptLoaded();

    expect(log).toHaveBeenCalledTimes(2);
  });

  it('replaces a clobbered global registry instead of throwing', () => {
    (globalThis as BannerHost).__nowoScriptBannerNames = {};

    expect(() => createBundleLogger('once-c').scriptLoaded()).not.toThrow();
    createBundleLogger('once-c').scriptLoaded();

    expect(log).toHaveBeenCalledTimes(1);
  });
});
