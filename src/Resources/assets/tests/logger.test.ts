/**
 * Unit tests for the bundle logger (createBundleLogger, scriptLoaded, setDebug, log levels).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger } from '../src/logger';

describe('createBundleLogger', () => {
  let consoleLog: ReturnType<typeof vi.fn>;
  let consoleDebug: ReturnType<typeof vi.fn>;
  let consoleInfo: ReturnType<typeof vi.fn>;
  let consoleWarn: ReturnType<typeof vi.fn>;
  let consoleError: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    consoleLog = vi.fn();
    consoleDebug = vi.fn();
    consoleInfo = vi.fn();
    consoleWarn = vi.fn();
    consoleError = vi.fn();
    vi.stubGlobal('console', {
      log: consoleLog,
      debug: consoleDebug,
      info: consoleInfo,
      warn: consoleWarn,
      error: consoleError,
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('scriptLoaded() logs without build id when no options', () => {
    const log = createBundleLogger('test');
    log.scriptLoaded();
    expect(consoleLog).toHaveBeenCalledTimes(1);
    expect(consoleLog.mock.calls[0]?.[0]).toContain('script loaded');
    expect(consoleLog.mock.calls[0]?.[0]).not.toContain('build: ');
  });

  it('scriptLoaded() logs with build id when buildTime is provided', () => {
    const log = createBundleLogger('test', { buildTime: '2026-01-15T12:00:00Z' });
    log.scriptLoaded();
    expect(consoleLog).toHaveBeenCalledTimes(1);
    expect(consoleLog.mock.calls[0]?.[0]).toContain('script loaded');
    expect(consoleLog.mock.calls[0]?.[0]).toContain('2026-01-15T12:00:00Z');
  });

  it('scriptLoaded() logs without build id when buildTime is empty string', () => {
    const log = createBundleLogger('test', { buildTime: '' });
    log.scriptLoaded();
    expect(consoleLog).toHaveBeenCalledTimes(1);
    expect(consoleLog.mock.calls[0]?.[0]).toContain('script loaded');
  });

  it('setDebug() enables debug; debug() logs when enabled', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.debug('msg');
    expect(consoleDebug).toHaveBeenCalled();
  });

  it('debug() is no-op when debug disabled', () => {
    const log = createBundleLogger('test');
    log.setDebug(false);
    log.debug('msg');
    expect(consoleDebug).not.toHaveBeenCalled();
  });

  it('debug() with no args calls console.debug with prefix only', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.debug();
    expect(consoleDebug).toHaveBeenCalledTimes(1);
  });

  it('debug() with object arg stringifies it', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.debug({ foo: 1 });
    expect(consoleDebug).toHaveBeenCalled();
    expect(consoleDebug.mock.calls[0]?.slice(2)).toContain('{"foo":1}');
  });

  it('info() logs when debug enabled', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.info('info msg');
    expect(consoleInfo).toHaveBeenCalled();
  });

  it('info() is no-op when debug disabled', () => {
    const log = createBundleLogger('test');
    log.info('msg');
    expect(consoleInfo).not.toHaveBeenCalled();
  });

  it('warn() logs when debug enabled', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.warn('warn msg');
    expect(consoleWarn).toHaveBeenCalled();
  });

  it('warn() is no-op when debug disabled', () => {
    const log = createBundleLogger('test');
    log.warn('msg');
    expect(consoleWarn).not.toHaveBeenCalled();
  });

  it('error() logs when debug enabled', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.error('err msg');
    expect(consoleError).toHaveBeenCalled();
  });

  it('error() is no-op when debug disabled', () => {
    const log = createBundleLogger('test');
    log.error('msg');
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('error() with Error instance passes it through (not stringified)', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    const err = new Error('fail');
    log.error(err);
    expect(consoleError).toHaveBeenCalled();
    expect(consoleError.mock.calls[0]?.slice(2)).toContain(err);
  });

  it('info() with no args calls console.info with prefix only', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.info();
    expect(consoleInfo).toHaveBeenCalledTimes(1);
  });

  it('warn() with no args calls console.warn with prefix only', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.warn();
    expect(consoleWarn).toHaveBeenCalledTimes(1);
  });

  it('error() with no args calls console.error with prefix only', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.error();
    expect(consoleError).toHaveBeenCalledTimes(1);
  });

  it('formatArgs passes through null (does not stringify)', () => {
    const log = createBundleLogger('test');
    log.setDebug(true);
    log.debug(null);
    expect(consoleDebug).toHaveBeenCalled();
    expect(consoleDebug.mock.calls[0]?.slice(2)).toContain(null);
  });

  describe('quiet switch', () => {
    afterEach(() => {
      vi.unstubAllGlobals();
      delete document.documentElement.dataset.nowoConsole;
      delete (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE;
    });

    it('scriptLoaded() prints nothing with <html data-nowo-console="quiet">', () => {
      document.documentElement.dataset.nowoConsole = 'quiet';
      createBundleLogger('test', { buildTime: '2026-11-23 14:05 UTC' }).scriptLoaded();
      expect(consoleLog).not.toHaveBeenCalled();
    });

    it('scriptLoaded() prints nothing with globalThis.NOWO_CONSOLE = "quiet"', () => {
      (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE = 'quiet';
      createBundleLogger('test').scriptLoaded();
      expect(consoleLog).not.toHaveBeenCalled();
    });

    it('other values keep the default output', () => {
      document.documentElement.dataset.nowoConsole = 'loud';
      (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE = 'verbose';
      createBundleLogger('test').scriptLoaded();
      expect(consoleLog).toHaveBeenCalledTimes(1);
    });

    it('prints when there is no document', () => {
      vi.stubGlobal('document', undefined);
      createBundleLogger('test').scriptLoaded();
      expect(consoleLog).toHaveBeenCalledTimes(1);
    });

    it('prints when reading the switch throws', () => {
      Object.defineProperty(globalThis, 'NOWO_CONSOLE', {
        configurable: true,
        get() {
          throw new Error('blocked');
        },
      });
      createBundleLogger('test').scriptLoaded();
      expect(consoleLog).toHaveBeenCalledTimes(1);
    });
  });
});
