import { afterEach, describe, expect, it, vi } from 'vitest';
import { PREFS_EVENT, saveChatPreference } from '../src/prefs';

describe('chat preferences outside the dock', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('saves one preference with the CSRF header and tells the dock', async () => {
    const prefs = { sound: 'bell', buzz: true, presence: true };
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ prefs }) });
    vi.stubGlobal('fetch', fetchMock);
    const heard = vi.fn();
    document.addEventListener(PREFS_EVENT, heard);

    expect(await saveChatPreference('/chat/api/settings', 'tok', 'soundMessage', 'bell')).toEqual(prefs);
    expect(fetchMock).toHaveBeenCalledWith('/chat/api/settings', expect.objectContaining({ method: 'POST', body: JSON.stringify({ key: 'soundMessage', value: 'bell' }) }));
    expect((fetchMock.mock.calls[0]?.[1] as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'tok' });
    expect((heard.mock.calls[0]?.[0] as CustomEvent).detail).toEqual(prefs);
    document.removeEventListener(PREFS_EVENT, heard);
  });

  it('throws when the server refuses the change', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 422 }));
    await expect(saveChatPreference('/s', 'tok', 'pushEnabled', true)).rejects.toThrow('422');
  });
});
