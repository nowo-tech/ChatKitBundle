/** text helpers for the chat dock (i18n interpolation, times, day separators). */

/**
 * Translation lookup with `%name%` placeholders.
 *
 * @param i18n Translated strings.
 * @param key Message key (returned as is when missing).
 * @param params Values for the `%name%` placeholders.
 * @returns The translated text.
 */
export function t(i18n: Record<string, string>, key: string, params: Record<string, string | number> = {}): string {
  let text = i18n[key] ?? key;
  for (const [name, value] of Object.entries(params)) {
    text = text.split(`%${name}%`).join(String(value));
  }
  return text;
}

/**
 * Whether two dates fall on the same local calendar day.
 *
 * @param a First date.
 * @param b Second date.
 * @returns True on the same day.
 */
function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/**
 * "Today" / "Yesterday" / a localised date, for the day separators of a thread.
 *
 * @param iso ISO 8601 date of the message.
 * @param i18n Translated strings (`today`, `yesterday`).
 * @param locale Locale for the date.
 * @param now Reference date (tests).
 * @returns The label (the year only when it is not the current one).
 */
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

/**
 * Localised time of a message (hours and minutes).
 *
 * @param iso ISO 8601 date.
 * @param locale Locale for the time.
 * @returns The time label.
 */
export function timeLabel(iso: string, locale: string): string {
  return new Date(iso).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}

/**
 * Today's messages: "now" under a minute, then "5 min ago" / "2 hours ago" (Intl.RelativeTimeFormat); null for older
 * days (the clock time is shown instead).
 *
 * @param iso ISO 8601 date of the message.
 * @param i18n Translated strings (`just_now`).
 * @param locale Locale for the label.
 * @param now Reference date (tests).
 * @returns The relative label, or null when the message is not from today.
 */
export function relativeTimeLabel(iso: string, i18n: Record<string, string>, locale: string, now: Date = new Date()): string | null {
  const date = new Date(iso);
  if (!sameDay(date, now)) {
    return null;
  }
  const minutes = Math.floor((now.getTime() - date.getTime()) / 60_000);
  if (minutes < 1) {
    return t(i18n, 'just_now');
  }
  const format = new Intl.RelativeTimeFormat(locale, { numeric: 'always', style: 'short' });
  return minutes < 60 ? format.format(-minutes, 'minute') : format.format(-Math.floor(minutes / 60), 'hour');
}

/**
 * Exact date and time of a message, for the tooltip of its time.
 *
 * @param iso ISO 8601 date.
 * @param locale Locale for the label.
 * @returns e.g. "Thursday, 16 October 2026, 14:05:09".
 */
export function exactTimeLabel(iso: string, locale: string): string {
  return new Date(iso).toLocaleString(locale, { dateStyle: 'full', timeStyle: 'medium' });
}

/**
 * Paints a message time: relative today (kept fresh by refreshRelativeTimes), the clock time on older days, and the
 * exact date and time as its tooltip.
 *
 * @param time The <time> element.
 * @param iso ISO 8601 date.
 * @param i18n Translated strings.
 * @param locale Locale.
 * @param now Reference date (tests).
 */
export function paintMessageTime(time: HTMLElement, iso: string, i18n: Record<string, string>, locale: string, now: Date = new Date()): void {
  const relative = relativeTimeLabel(iso, i18n, locale, now);
  time.setAttribute('datetime', iso);
  time.textContent = relative ?? timeLabel(iso, locale);
  time.title = exactTimeLabel(iso, locale);
  if (relative === null) {
    delete time.dataset.relative;
  } else {
    // What the shared ticker needs to re-paint it without the thread (locale, "now" label).
    time.dataset.relative = '';
    time.dataset.locale = locale;
    time.dataset.justNow = t(i18n, 'just_now');
  }
  startRelativeTicker();
}

/**
 * Re-paints every relative message time on the page ("5 min ago" → "6 min ago"; yesterday's turn into clock times).
 *
 * @param scope Root to look in.
 * @param now Reference date (tests).
 */
export function refreshRelativeTimes(scope: ParentNode = document, now: Date = new Date()): void {
  scope.querySelectorAll<HTMLElement>('time[data-relative]').forEach((time) => {
    const iso = time.getAttribute('datetime');
    if (iso !== null) {
      paintMessageTime(time, iso, { just_now: time.dataset.justNow ?? '' }, time.dataset.locale ?? 'en', now);
    }
  });
}

let ticker: number | undefined;

/** One page-wide 30 s ticker for every open thread (started by the first relative time painted). */
export function startRelativeTicker(): void {
  if (ticker === undefined) {
    ticker = window.setInterval(() => refreshRelativeTimes(), 30_000);
  }
}

/** Stops the shared ticker (tests). */
export function stopRelativeTicker(): void {
  window.clearInterval(ticker);
  ticker = undefined;
}

/**
 * Compact time for the conversation list: time today, weekday this week, else a short date.
 *
 * @param iso ISO 8601 date of the last message, or null.
 * @param locale Locale for the label.
 * @param now Reference date (tests).
 * @returns The label ("" without a date).
 */
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

/**
 * Messages of the same author within 5 minutes share one header (avatar + name).
 *
 * @param previous Previous message, if any.
 * @param current Message being rendered.
 * @returns True when the current message starts a new group.
 */
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

/**
 * Whether a message needs a day separator before it.
 *
 * @param previous Previous message, if any.
 * @param current Message being rendered.
 * @returns True for the first message or a different day than the previous one.
 */
export function isNewDay(previous: { createdAt: string } | undefined, current: { createdAt: string }): boolean {
  return previous === undefined || !sameDay(new Date(previous.createdAt), new Date(current.createdAt));
}
