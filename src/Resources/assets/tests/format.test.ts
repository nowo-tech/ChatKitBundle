import { describe, expect, it } from 'vitest';
import { dayLabel, isNewDay, listTimeLabel, startsGroup, t, timeLabel } from '../src/format';

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
});
