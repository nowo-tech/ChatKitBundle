/**
 * =============================================================================
 * BUNDLE LOGGER - Reusable across bundles
 * =============================================================================
 *
 * USAGE (English):
 *
 * 1. Create a logger for your bundle (typically at the top of your entry file):
 *
 *    import { createBundleLogger } from './logger';
 *    declare const __MY_BUNDLE_BUILD_TIME__: string;  // optional, injected by Vite define
 *
 *    const log = createBundleLogger('my-bundle', {
 *      buildTime: typeof __MY_BUNDLE_BUILD_TIME__ !== 'undefined' ? __MY_BUNDLE_BUILD_TIME__ : undefined,
 *    });
 *
 * 2. Log that the script loaded (call once at startup; includes build id if provided):
 *
 *    log.scriptLoaded();
 *
 * 3. Call setDebug(true) when your bundle's debug mode is on (e.g. from config or data attribute).
 *    When debug is false, only scriptLoaded() output is shown; info/debug/warn/error are no-ops.
 *
 * 4. Use level methods for the rest of the bundle:
 *
 *    log.debug('detailed info', { foo: 1 });
 *    log.info('something happened');
 *    log.warn('unexpected but handled', error);
 *    log.error('failure', error);
 *
 * 5. To inject the build time with Vite, add to vite.config.ts (see buildTimeLabel() in this bundle's config: it
 *    reads/writes the committed build-info.json, so unchanged sources keep the same build time):
 *
 *    define: {
 *      __MY_BUNDLE_BUILD_TIME__: JSON.stringify(buildTimeLabel('src/Resources/assets/src')), // "2026-11-23 14:05 UTC"
 *    },
 *
 * 6. Quiet switch: scriptLoaded() prints nothing when <html data-nowo-console="quiet"> or
 *    globalThis.NOWO_CONSOLE === 'quiet'. The default (print) is unchanged.
 *
 * All methods support multiple arguments (like console.log). Objects are stringified
 * for readability in debug/info/warn/error. The prefix is [name] and each level
 * has a distinct style and emoji when the console supports it.
 * =============================================================================
 */

/** True when the page asked for a silent console (`<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`). */
function isQuiet(): boolean {
  try {
    if ((globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE === 'quiet') return true;
    return typeof document !== 'undefined' && document.documentElement.dataset.nowoConsole === 'quiet';
  } catch {
    return false;
  }
}

export type BundleLoggerOptions = {
  /** If set, scriptLoaded() will include this (e.g. the build time label "2026-11-23 14:05 UTC"). */
  buildTime?: string;
};

export type BundleLogger = {
  /** Call once at startup. Logs "script loaded" and optional build time. Shown unless the quiet switch is set. */
  scriptLoaded: () => void;
  /** When false, debug/info/warn/error are no-ops (only scriptLoaded is shown). */
  setDebug: (enabled: boolean) => void;
  debug: (...args: unknown[]) => void;
  info: (...args: unknown[]) => void;
  warn: (...args: unknown[]) => void;
  error: (...args: unknown[]) => void;
};

const STYLES = {
  script: 'color:#0ea5e9;font-weight:bold',
  debug: 'color:#6b7280',
  info: 'color:#2563eb',
  warn: 'color:#d97706',
  error: 'color:#dc2626;font-weight:bold',
} as const;

const EMOJI = {
  script: '📦',
  debug: '🔍',
  info: 'ℹ️',
  warn: '⚠️',
  error: '❌',
} as const;

function formatArgs(args: unknown[]): unknown[] {
  return args.map((a) =>
    typeof a === 'object' && a !== null && !(a instanceof Error) ? JSON.stringify(a) : a,
  );
}

/**
 * Creates a logger for a bundle. Use the same logger instance across the bundle.
 *
 * @param name - Short name for log prefix (e.g. 'icon-selector').
 * @param options - Optional buildTime for scriptLoaded().
 */
export function createBundleLogger(name: string, options: BundleLoggerOptions = {}): BundleLogger {
  const prefix = `[${name}]`;
  const { buildTime } = options;
  let debugEnabled = false;

  return {
    scriptLoaded(): void {
      if (isQuiet()) return;
      if (alreadyAnnounced(name)) return;
      if (buildTime !== undefined && buildTime !== '') {
        console.log(
          `%c${EMOJI.script} ${prefix} script loaded, build: %c${buildTime}`,
          STYLES.script,
          'color:#059669',
        );
      } else {
        console.log(`%c${EMOJI.script} ${prefix} script loaded`, STYLES.script);
      }
    },

    setDebug(enabled: boolean): void {
      debugEnabled = enabled;
    },

    debug(...args: unknown[]): void {
      if (!debugEnabled) return;
      if (args.length > 0) {
        console.debug(`%c${EMOJI.debug} ${prefix}`, STYLES.debug, ...formatArgs(args));
      } else {
        console.debug(`%c${EMOJI.debug} ${prefix}`, STYLES.debug);
      }
    },

    info(...args: unknown[]): void {
      if (!debugEnabled) return;
      if (args.length > 0) {
        console.info(`%c${EMOJI.info} ${prefix}`, STYLES.info, ...formatArgs(args));
      } else {
        console.info(`%c${EMOJI.info} ${prefix}`, STYLES.info);
      }
    },

    warn(...args: unknown[]): void {
      if (!debugEnabled) return;
      if (args.length > 0) {
        console.warn(`%c${EMOJI.warn} ${prefix}`, STYLES.warn, ...formatArgs(args));
      } else {
        console.warn(`%c${EMOJI.warn} ${prefix}`, STYLES.warn);
      }
    },

    error(...args: unknown[]): void {
      if (!debugEnabled) return;
      if (args.length > 0) {
        console.error(`%c${EMOJI.error} ${prefix}`, STYLES.error, ...formatArgs(args));
      } else {
        console.error(`%c${EMOJI.error} ${prefix}`, STYLES.error);
      }
    },
  };
}

/**
 * Records `name` in a page-wide `Set` on `globalThis` and tells whether it was announced before (REQ-ASSETS-005): a
 * script that is evaluated twice (Turbo, a fragment, two copies, two loggers with the same name) prints its
 * "script loaded" line once per page. Page HTML that clobbers the global (DOM named access) is replaced by a fresh set.
 *
 * @param name - Logger name in kebab-case, e.g. `icon-selector` or `ui-kit/dialog`.
 * @returns True when the line was already printed on this page.
 */
function alreadyAnnounced(name: string): boolean {
  const host = globalThis as { __nowoScriptBannerNames?: unknown };
  if (!(host.__nowoScriptBannerNames instanceof Set)) {
    host.__nowoScriptBannerNames = new Set<string>();
  }
  const seen = host.__nowoScriptBannerNames as Set<string>;
  if (seen.has(name)) {
    return true;
  }
  seen.add(name);

  return false;
}
