import { afterEach, describe, expect, it, vi } from 'vitest';
import { handOffSecret, SECRET_HANDOFF, SECRET_HANDOFF_PROBE, secretHandoffAvailable, showSecretWarning } from '../src/secret-handoff';
import { BEFORE_SEND, beforeSend } from '../src/slots';
import { templates } from './support';

const i18n: Record<string, string> = { 'secret_handoff.warning': 'This looks like a secret.' };

describe('secret hand-off (2.7, D-20261126-11)', () => {
  afterEach(() => document.body.replaceChildren());

  it('is offered only with the flag and a host that answers the probe', () => {
    const root = document.createElement('div');
    document.body.append(root);
    expect(secretHandoffAvailable(root, true)).toBe(false);
    const probe = vi.fn((event: Event) => event.preventDefault());
    document.addEventListener(SECRET_HANDOFF_PROBE, probe);
    expect(secretHandoffAvailable(root, false)).toBe(false);
    expect(probe).not.toHaveBeenCalled();
    expect(secretHandoffAvailable(root, true)).toBe(true);
    document.removeEventListener(SECRET_HANDOFF_PROBE, probe);
  });

  it('hands the text over in the browser; a host may cancel it', () => {
    const root = document.createElement('div');
    document.body.append(root);
    const seen: unknown[] = [];
    const listener = (event: Event): void => {
      seen.push((event as CustomEvent).detail);
    };
    document.addEventListener(SECRET_HANDOFF, listener);
    expect(handOffSecret(root, 'c1', 'hunter2', 'edit')).toBe(true);
    expect(seen).toEqual([{ text: 'hunter2', conversation: 'c1', kind: 'edit' }]);
    expect(Object.isFrozen(seen[0])).toBe(true);
    const cancel = (event: Event): void => event.preventDefault();
    root.addEventListener(SECRET_HANDOFF, cancel);
    expect(handOffSecret(root, 'c1', 'x', 'send')).toBe(false);
    document.removeEventListener(SECRET_HANDOFF, listener);
  });

  it('shows the inline warning with UiKit buttons, primary first; each choice closes it', () => {
    const form = document.createElement('form');
    document.body.append(form);
    const handOff = vi.fn();
    const sendAnyway = vi.fn();
    const done = vi.fn();
    const warning = showSecretWarning(form, templates(), { i18n, handOff, sendAnyway, done });
    const buttons = [...warning.querySelectorAll('button')];
    expect(buttons.map((button) => button.dataset.part)).toEqual(['handoff', 'send', 'cancel']);
    expect(buttons.every((button) => button.type === 'button')).toBe(true);
    expect(warning.querySelector('[data-part="text"]')?.textContent).toBe('This looks like a secret.');
    expect(warning.querySelector('[role="alert"]')).not.toBeNull();
    expect(document.activeElement).toBe(buttons[0]);
    buttons[0]?.click();
    expect(handOff).toHaveBeenCalled();
    expect(done).toHaveBeenCalledTimes(1);
    expect(form.previousElementSibling).toBeNull();

    // Without the hand-off: no third button; focus on Send anyway; a new warning replaces the old one.
    const plain = showSecretWarning(form, templates(), { i18n, message: 'Looks like an API key', sendAnyway });
    expect(plain.querySelector('[data-part="handoff"]')).toBeNull();
    expect(plain.querySelector('[data-part="text"]')?.textContent).toBe('Looks like an API key');
    const again = showSecretWarning(form, templates(), { i18n, sendAnyway });
    expect(document.querySelectorAll('[data-secret-warning]')).toHaveLength(1);
    (again.querySelector('[data-part="send"]') as HTMLButtonElement).click();
    expect(sendAnyway).toHaveBeenCalledTimes(1);
    const third = showSecretWarning(form, templates(), { i18n, sendAnyway });
    (third.querySelector('[data-part="cancel"]') as HTMLButtonElement).click();
    expect(document.querySelector('[data-secret-warning]')).toBeNull();
    const fourth = showSecretWarning(form, templates(), { i18n, sendAnyway });
    fourth.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
    expect(document.querySelector('[data-secret-warning]')).not.toBeNull();
    fourth.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(document.querySelector('[data-secret-warning]')).toBeNull();
    expect(sendAnyway).toHaveBeenCalledTimes(1);
  });

  it('adds warn() and (opt-in only) handOff() to nowo-chat:before-send; each acts once', () => {
    const root = document.createElement('div');
    document.body.append(root);
    const details: Record<string, unknown>[] = [];
    root.addEventListener(BEFORE_SEND, (event) => {
      details.push((event as CustomEvent).detail);
    });
    const handOff = vi.fn();
    const send = vi.fn();
    beforeSend(root, 'c1', 'x', send, 'send', { warn: vi.fn(), handOff });
    beforeSend(root, 'c1', 'x', send);
    expect('handOff' in (details[0] ?? {})).toBe(true);
    expect('warn' in (details[1] ?? {})).toBe(false);
    expect('handOff' in (details[1] ?? {})).toBe(false);
    const first = details[0] as { handOff: () => void; sendAnyway: () => void };
    first.handOff();
    first.sendAnyway();
    first.handOff();
    expect(handOff).toHaveBeenCalledTimes(1);
    expect(send).not.toHaveBeenCalled();
  });
});
