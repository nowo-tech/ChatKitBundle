import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  dayLabel,
  exactTimeLabel,
  isNewDay,
  listTimeLabel,
  paintMessageTime,
  refreshRelativeTimes,
  relativeTimeLabel,
  startRelativeTicker,
  startsGroup,
  stopRelativeTicker,
  t,
  timeLabel,
} from '../src/format';

const i18n = { today: 'Today', yesterday: 'Yesterday', hi: 'Hi %name%, %name%! %n%' };
const now = new Date('2026-10-15T12:00:00');

describe('chat format', () => {
  it('interpolates every placeholder and falls back to the key', () => {
    expect(t(i18n, 'hi', { name: 'Ana', n: 3 })).toBe('Hi Ana, Ana! 3');
    expect(t(i18n, 'missing')).toBe('missing');
  });

  it('labels days relative to now', () => {
    expect(dayLabel('2026-10-15T08:00:00', i18n, 'en', now)).toBe('Today');
    expect(dayLabel('2026-10-14T23:00:00', i18n, 'en', now)).toBe('Yesterday');
    expect(dayLabel('2026-10-01T08:00:00', i18n, 'en', now)).toBe('October 1');
    expect(dayLabel('2025-10-01T08:00:00', i18n, 'en', now)).toBe('October 1, 2025');
    expect(dayLabel('2026-10-15T08:00:00', i18n, 'en')).toMatch(/\w/);
  });

  it('formats list times: time today, weekday this week, short date before', () => {
    expect(listTimeLabel(null, 'en', now)).toBe('');
    expect(listTimeLabel('2026-10-15T09:05:00', 'en', now)).toBe(timeLabel('2026-10-15T09:05:00', 'en'));
    expect(listTimeLabel('2026-10-13T09:05:00', 'en', now)).toBe('Tue');
    expect(listTimeLabel('2026-09-01T09:05:00', 'en', now)).toBe('Sep 1');
    expect(listTimeLabel('2026-10-15T09:05:00', 'en')).not.toBe('');
  });

  it('groups by author within five minutes on the same day', () => {
    const a = { authorUuid: 'a', createdAt: '2026-10-15T10:00:00' };
    expect(startsGroup(undefined, a)).toBe(true);
    expect(startsGroup(a, { authorUuid: 'b', createdAt: '2026-10-15T10:01:00' })).toBe(true);
    expect(startsGroup(a, { authorUuid: 'a', createdAt: '2026-10-15T10:04:00' })).toBe(false);
    expect(startsGroup(a, { authorUuid: 'a', createdAt: '2026-10-15T10:06:00' })).toBe(true);
    expect(startsGroup({ authorUuid: 'a', createdAt: '2026-10-14T23:59:00' }, { authorUuid: 'a', createdAt: '2026-10-15T00:01:00' })).toBe(true);
    expect(isNewDay(undefined, a)).toBe(true);
    expect(isNewDay(a, { createdAt: '2026-10-15T23:00:00' })).toBe(false);
    expect(isNewDay(a, { createdAt: '2026-10-16T00:00:00' })).toBe(true);
  });

  describe('relative message times', () => {
    afterEach(() => {
      stopRelativeTicker();
      vi.useRealTimers();
    });

    it('says "now", minutes and hours ago today, and nothing for older days', () => {
      const strings = { just_now: 'now' };
      expect(relativeTimeLabel('2026-10-15T11:59:40', strings, 'en', now)).toBe('now');
      expect(relativeTimeLabel('2026-10-15T11:55:00', strings, 'en', now)).toBe('5 min. ago');
      expect(relativeTimeLabel('2026-10-15T09:30:00', strings, 'en', now)).toBe('2 hr. ago');
      expect(relativeTimeLabel('2026-10-14T23:59:00', strings, 'en', now)).toBeNull();
      expect(relativeTimeLabel(new Date().toISOString(), strings, 'en')).toBe('now');
    });

    it('gives the exact date and time for the tooltip', () => {
      expect(exactTimeLabel('2026-10-15T11:55:09', 'en')).toBe('Thursday, October 15, 2026 at 11:55:09 AM');
    });

    it('paints today relative (refreshable) and older days as clock times, both with the exact tooltip', () => {
      const time = document.createElement('time');
      document.body.append(time);
      paintMessageTime(time, '2026-10-15T11:55:00', { just_now: 'now' }, 'en', now);
      expect(time.textContent).toBe('5 min. ago');
      expect(time.title).toContain('11:55:00');
      expect(time.dataset.relative).toBe('');
      expect(time.getAttribute('datetime')).toBe('2026-10-15T11:55:00');

      refreshRelativeTimes(document, new Date('2026-10-15T12:10:00'));
      expect(time.textContent).toBe('15 min. ago');
      refreshRelativeTimes(document, new Date('2026-10-16T09:00:00'));
      expect(time.textContent).toBe(timeLabel('2026-10-15T11:55:00', 'en'));
      expect(time.dataset.relative).toBeUndefined();

      time.dataset.relative = '';
      time.removeAttribute('datetime');
      refreshRelativeTimes();
      expect(time.textContent).toBe(timeLabel('2026-10-15T11:55:00', 'en'));
      time.remove();
    });

    it('keeps today\'s times fresh with one shared 30 s ticker', () => {
      vi.useFakeTimers();
      vi.setSystemTime(new Date('2026-10-15T12:00:00'));
      stopRelativeTicker();
      const time = document.createElement('time');
      document.body.append(time);
      time.dataset.locale = 'en';
      paintMessageTime(time, '2026-10-15T11:59:50', { just_now: 'now' }, 'en');
      startRelativeTicker();
      expect(time.textContent).toBe('now');
      vi.setSystemTime(new Date('2026-10-15T12:02:00'));
      vi.advanceTimersByTime(30_000);
      expect(time.textContent).toBe('2 min. ago');
      // Without the stored locale / label the ticker falls back to English.
      delete time.dataset.justNow;
      delete time.dataset.locale;
      vi.advanceTimersByTime(30_000);
      expect(time.textContent).toBe('3 min. ago');
      time.remove();
    });
  });
});

