/**
 * 2.7 (Beacon D-20261126-11, owner OK): "Send as a secret" in the secret warning of `nowo-chat:before-send`.
 *
 * The host opts in twice, and the dock checks both at the moment of the warning:
 * 1. `web_ui.dock.secret_handoff: true` (rendered into the dock configuration), and
 * 2. a listener that answers `nowo-chat:secret-handoff-probe` (bubbling, cancelable) with `preventDefault()` on the dock
 *    root or an ancestor: the host's dialog is really there on this page.
 *
 * On "Send as a secret" the dock dispatches `nowo-chat:secret-handoff` (bubbling, cancelable) with
 * `{text, conversation, kind}`: the draft travels **in the browser only** (never to the server, a URL, logs or
 * analytics). Unless the host cancels it, the draft is then cleared and its stored copy purged, and nothing is sent.
 * The host opens its own dialog (Beacon: the Yopass "new secret" dialog, prefilled) and, once sealed, inserts the
 * `one_time_secret` card through `nowo-chat:insert-card` (the 2.6 card path).
 *
 * This is the one deliberate exception to the 2.6 rule "a host dialog never receives the draft": the person chooses it.
 * Without the opt-in the dock behaves exactly as in 2.6 (no third button, no event).
 */
import { t } from './format';
import type { SendKind } from './slots';
import type { Templates } from './templates';
import { part } from './templates';

/** The hand-off event (dock → host). */
export const SECRET_HANDOFF = 'nowo-chat:secret-handoff';

/** The probe the host answers with `preventDefault()` when it takes hand-offs on this page. */
export const SECRET_HANDOFF_PROBE = 'nowo-chat:secret-handoff-probe';

/** What `nowo-chat:secret-handoff` carries. */
export interface SecretHandoffDetail {
  readonly text: string;
  readonly conversation: string;
  readonly kind: SendKind;
}

/**
 * Whether "Send as a secret" may be offered now: the configuration flag, and a host listener that answers the probe.
 *
 * @param root The dock root.
 * @param enabled `web_ui.dock.secret_handoff`.
 * @returns True when both opt-ins are there.
 */
export function secretHandoffAvailable(root: HTMLElement, enabled: boolean): boolean {
  if (!enabled) {
    return false;
  }
  return !root.dispatchEvent(new CustomEvent(SECRET_HANDOFF_PROBE, { bubbles: true, cancelable: true }));
}

/**
 * Hands the draft to the host, in the browser.
 *
 * @param root The dock root.
 * @param conversation The conversation uuid.
 * @param text The draft (or the flagged text).
 * @param kind Where it was written: the composer, a thread reply or an edit.
 * @returns True when handed off (the host did not cancel): the caller clears the draft.
 */
export function handOffSecret(root: HTMLElement, conversation: string, text: string, kind: SendKind): boolean {
  const detail: SecretHandoffDetail = Object.freeze({ text, conversation, kind });
  return root.dispatchEvent(new CustomEvent<SecretHandoffDetail>(SECRET_HANDOFF, { detail, bubbles: true, cancelable: true }));
}

/** The choices of the dock's secret warning. */
export interface SecretWarningOptions {
  i18n: Record<string, string>;
  /** The host's text (plain text; the default says "This looks like a secret"). */
  message?: string;
  /** "Send as a secret" (absent: not offered). */
  handOff?: () => void;
  /** "Send anyway". */
  sendAnyway: () => void;
  /** After any choice, or Cancel / Escape: focus back to the text box. */
  done?: () => void;
}

/**
 * Shows the dock's inline secret warning before `anchor` (one at a time per anchor). Native buttons with UiKit's
 * classes (from the template), primary first: "Send as a secret" when offered, then "Send anyway" and "Cancel".
 * Focus moves to the first button; Escape cancels.
 *
 * @param anchor The element the warning goes before (the composer or the editor).
 * @param templates The dock templates (`secret-warning`).
 * @param options Texts and actions.
 * @returns The warning element.
 */
export function showSecretWarning(anchor: HTMLElement, templates: Templates, options: SecretWarningOptions): HTMLElement {
  anchor.parentElement?.querySelector('[data-secret-warning]')?.remove();
  const warning = templates.clone('secret-warning');
  warning.dataset.secretWarning = '';
  part(warning, 'text').textContent = options.message ?? t(options.i18n, 'secret_handoff.warning');
  const handOff = part<HTMLButtonElement>(warning, 'handoff');
  const send = part<HTMLButtonElement>(warning, 'send');
  const cancel = part<HTMLButtonElement>(warning, 'cancel');
  const close = (): void => {
    warning.remove();
    options.done?.();
  };
  if (options.handOff === undefined) {
    handOff.remove();
  } else {
    const run = options.handOff;
    handOff.addEventListener('click', () => {
      close();
      run();
    });
  }
  send.addEventListener('click', () => {
    close();
    options.sendAnyway();
  });
  cancel.addEventListener('click', close);
  warning.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  anchor.before(warning);
  (options.handOff === undefined ? send : handOff).focus();
  return warning;
}
