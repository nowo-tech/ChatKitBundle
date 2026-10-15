/** text helpers for the chat dock (i18n interpolation, times, day separators). */

export function t(i18n: Record<string, string>, key: string, params: Record<string, string | number> = {}): string {
  let text = i18n[key] ?? key;
  for (const [name, value] of Object.entries(params)) {
    text = text.split(`%${name}%`).join(String(value));
  }
  return text;
}

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/** "Today" / "Yesterday" / a localised date, for the day separators of a thread. */
export function dayLabel(iso: string, i18n: Record<string, string>, locale: string, now: Date = new Date()): string {
  const date = new Date(iso);
  if (sameDay(date, now)) {
    return t(i18n, 'today');
  }
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (sameDay(date, yesterday)) {
    return t(i18n, 'yesterday');
  }
  return date.toLocaleDateString(locale, {
    day: 'numeric',
    month: 'long',
    year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
  });
}

export function timeLabel(iso: string, locale: string): string {
  return new Date(iso).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}

/** Compact time for the conversation list: time today, weekday this week, else a short date. */
export function listTimeLabel(iso: string | null, locale: string, now: Date = new Date()): string {
  if (iso === null) {
    return '';
  }
  const date = new Date(iso);
  if (sameDay(date, now)) {
    return timeLabel(iso, locale);
  }
  const days = (now.getTime() - date.getTime()) / 86_400_000;
  if (days < 6) {
    return date.toLocaleDateString(locale, { weekday: 'short' });
  }
  return date.toLocaleDateString(locale, { day: 'numeric', month: 'short' });
}

/** Messages of the same author within 5 minutes share one header (avatar + name). */
export function startsGroup(
  previous: { authorUuid: string | null; createdAt: string } | undefined,
  current: { authorUuid: string | null; createdAt: string },
): boolean {
  if (previous === undefined || previous.authorUuid !== current.authorUuid) {
    return true;
  }
  const gap = new Date(current.createdAt).getTime() - new Date(previous.createdAt).getTime();
  return gap > 5 * 60_000 || !sameDay(new Date(previous.createdAt), new Date(current.createdAt));
}

export function isNewDay(previous: { createdAt: string } | undefined, current: { createdAt: string }): boolean {
  return previous === undefined || !sameDay(new Date(previous.createdAt), new Date(current.createdAt));
}
