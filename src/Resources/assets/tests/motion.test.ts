import { afterEach, describe, expect, it, vi } from 'vitest';
import { CLOSING_ATTR, closeWithEffect, effectMs, finishClosing, isClosing } from '../src/motion';

const withTransition = (duration: string, delay = '0s'): void => {
  vi.spyOn(window, 'getComputedStyle').mockReturnValue({ transitionDuration: duration, transitionDelay: delay } as CSSStyleDeclaration);
};

describe('motion', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it('reads the longest transition, in seconds or ms, with its delay', () => {
    const element = document.createElement('div');
    withTransition('0.1s, 200ms', '0s, 50ms');
    expect(effectMs(element)).toBe(250);
    withTransition('var(--unresolved)');
    expect(effectMs(element)).toBe(0);
    vi.restoreAllMocks();
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({} as CSSStyleDeclaration);
    expect(effectMs(element)).toBe(0);
  });

  it('closes in the same task without a transition', () => {
    const element = document.createElement('div');
    const close = vi.fn();
    closeWithEffect(element, close);
    expect(close).toHaveBeenCalledOnce();
    expect(element.hasAttribute(CLOSING_ATTR)).toBe(false);
    expect(isClosing(element)).toBe(false);
  });

  it('waits for its own transitionend, once, and ignores a child and a second call', () => {
    const element = document.createElement('div');
    const child = element.appendChild(document.createElement('span'));
    const close = vi.fn();
    withTransition('0.15s');
    closeWithEffect(element, close);
    closeWithEffect(element, close);
    expect(element.hasAttribute(CLOSING_ATTR)).toBe(true);
    expect(isClosing(element)).toBe(true);
    child.dispatchEvent(new Event('transitionend', { bubbles: true }));
    expect(close).not.toHaveBeenCalled();
    element.dispatchEvent(new Event('transitionend'));
    expect(close).toHaveBeenCalledOnce();
    expect(element.hasAttribute(CLOSING_ATTR)).toBe(false);
  });

  it('falls back to a timer if transitionend never fires', () => {
    vi.useFakeTimers();
    const element = document.createElement('div');
    const close = vi.fn();
    withTransition('100ms');
    closeWithEffect(element, close);
    vi.advanceTimersByTime(149);
    expect(close).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(close).toHaveBeenCalledOnce();
  });

  it('finishes a fade at once on request, and does nothing with no fade running', () => {
    const element = document.createElement('div');
    const close = vi.fn();
    finishClosing(element);
    withTransition('100ms');
    closeWithEffect(element, close);
    finishClosing(element);
    expect(close).toHaveBeenCalledOnce();
    expect(isClosing(element)).toBe(false);
  });

  it('finishes every running fade on turbo:before-cache, once each, so that a Turbo snapshot holds none', () => {
    const first = document.createElement('div');
    const second = document.createElement('div');
    const closeFirst = vi.fn();
    const closeSecond = vi.fn();
    withTransition('100ms');
    closeWithEffect(first, closeFirst);
    closeWithEffect(second, closeSecond);
    document.dispatchEvent(new Event('turbo:before-cache'));
    expect(closeFirst).toHaveBeenCalledOnce();
    expect(closeSecond).toHaveBeenCalledOnce();
    expect(first.hasAttribute(CLOSING_ATTR)).toBe(false);
    expect(second.hasAttribute(CLOSING_ATTR)).toBe(false);
    expect(isClosing(first)).toBe(false);
    document.dispatchEvent(new Event('turbo:before-cache'));
    expect(closeFirst).toHaveBeenCalledOnce();
  });
});
