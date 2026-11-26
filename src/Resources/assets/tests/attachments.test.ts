import { afterEach, describe, expect, it, vi } from 'vitest';
import { AttachmentTray, downloadFile, extensionOf, followDownload, formatSize, renderFiles, type AttachmentApi } from '../src/attachments';
import type { AttachmentStub } from '../src/types';

const i18n: Record<string, string> = {
  'attachment.attach': 'Attach a file',
  'attachment.attach_hint': 'Up to %max% each',
  'attachment.files': 'Files',
  'attachment.too_many': 'At most %max%',
  'attachment.too_large': 'Larger than %max%',
  'attachment.not_allowed': 'Not allowed',
  'attachment.error': 'Upload failed',
  'attachment.uploading': 'Uploading',
  'attachment.scanning': 'Scanning',
  'attachment.clean': 'Ready',
  'attachment.blocked': 'Blocked',
  'attachment.failed': 'Failed',
  'attachment.remove': 'Remove',
  'attachment.download': 'Download',
  'attachment.waiting': 'Waiting',
};
const stub = (over: Partial<AttachmentStub> = {}): AttachmentStub => ({ uuid: 'a1', name: 'notes.md', extension: 'md', mediaType: 'text/markdown', kind: 'markdown', size: 10, state: 'uploaded', ...over });
const icon = (name: string): Element => {
  const span = document.createElement('span');
  span.dataset.icon = name;
  return span;
};
const file = (name: string, size = 5): File => new File(['x'.repeat(size)], name);

function tray(api: Partial<AttachmentApi> = {}, settings: Partial<{ maxBytes: number; maxPerMessage: number; extensions: string[] }> = {}) {
  const calls = {
    uploadAttachment: vi.fn().mockImplementation(async (_c: string, f: File) => stub({ uuid: `u-${f.name}`, name: f.name })),
    attachmentDrafts: vi.fn().mockResolvedValue([]),
    attachment: vi.fn().mockResolvedValue({ attachment: stub(), url: '/chat/files/a1/original?e=1&s=x' }),
    removeAttachment: vi.fn().mockResolvedValue(undefined),
    ...api,
  };
  const changed = vi.fn();
  const focusAfterRemove = vi.fn();
  const instance = new AttachmentTray({
    i18n,
    settings: { upload: '/u/__uuid__', drafts: '/d/__uuid__', attachment: '/a/__uuid__', maxBytes: 100, maxPerMessage: 3, extensions: ['md', 'txt', 'pdf'], ...settings },
    api: calls as AttachmentApi,
    conversationUuid: () => 'c1',
    icon,
    changed,
    focusAfterRemove,
    key: () => 'k',
  });
  document.body.append(instance.button, instance.input, instance.element);
  return { instance, calls, changed, focusAfterRemove };
}

describe('attachments (2.7)', () => {
  afterEach(() => {
    vi.useRealTimers();
    document.body.replaceChildren();
  });

  it('formats sizes and extensions', () => {
    expect([formatSize(12), formatSize(2048), formatSize(3 * 1024 * 1024)]).toEqual(['12 B', '2 KB', '3.0 MB']);
    expect([extensionOf('a.PDF'), extensionOf('noext'), extensionOf('.env')]).toEqual(['pdf', '', 'env']);
  });

  it('builds a native attach button and a hidden file input limited to the allowed extensions', () => {
    const { instance } = tray();
    expect(instance.button.tagName).toBe('BUTTON');
    expect(instance.button.type).toBe('button');
    expect(instance.button.getAttribute('aria-label')).toBe('Attach a file');
    expect(instance.button.title).toBe('Up to 100 B each');
    expect(instance.input.type).toBe('file');
    expect(instance.input.multiple).toBe(true);
    expect(instance.input.accept).toBe('.md,.txt,.pdf');
    expect(instance.element.hidden).toBe(true);
    const click = vi.spyOn(instance.input, 'click').mockImplementation(() => undefined);
    instance.button.click();
    expect(click).toHaveBeenCalled();
  });

  it('uploads picked files, shows their states and tracks readiness', async () => {
    const { instance, calls, changed } = tray();
    Object.defineProperty(instance.input, 'files', { value: [file('notes.md')], configurable: true });
    instance.input.dispatchEvent(new Event('change'));
    await vi.waitFor(() => expect(calls.uploadAttachment).toHaveBeenCalledWith('c1', expect.any(File), 'k'));
    await vi.waitFor(() => expect(instance.uuids).toEqual(['u-notes.md']));
    expect(instance.hasFiles).toBe(true);
    expect(instance.ready).toBe(false);
    expect(instance.element.hidden).toBe(false);
    const chip = instance.element.querySelector('.nowo-chat-file') as HTMLElement;
    expect(chip.dataset.state).toBe('uploaded');
    expect(chip.querySelector('progress')?.getAttribute('aria-label')).toBe('Scanning');
    expect(chip.querySelector('.nowo-chat-file__name')?.textContent).toBe('notes.md');
    instance.setState('u-notes.md', 'clean');
    instance.setState('unknown', 'blocked');
    expect(instance.ready).toBe(true);
    expect(chip.querySelector('.nowo-chat-file__state')?.textContent).toBe('Ready');
    expect(changed).toHaveBeenCalled();
    instance.reset();
    expect(instance.hasFiles).toBe(false);
    expect(instance.element.hidden).toBe(true);
  });

  it('refuses files before uploading: too many, too large, not allowed', async () => {
    const { instance, calls } = tray({}, { maxPerMessage: 1 });
    await instance.addFiles([file('big.md', 500), file('virus.exe'), file('a.md'), file('b.md')]);
    expect(calls.uploadAttachment).toHaveBeenCalledTimes(1);
    expect(instance.element.querySelector('[role="status"]')?.textContent).toBe('At most 1');
    const second = tray();
    await second.instance.addFiles([file('big.md', 500)]);
    expect(second.instance.element.querySelector('[role="status"]')?.textContent).toBe('Larger than 100 B');
    await second.instance.addFiles([file('run.exe')]);
    expect(second.instance.element.querySelector('[role="status"]')?.textContent).toBe('Not allowed');
    expect(second.calls.uploadAttachment).not.toHaveBeenCalled();
  });

  it('shows the server refusals and treats a failed upload as rejected', async () => {
    for (const [code, text] of [['file_too_large', 'Larger than 100 B'], ['type_mismatch', 'Not allowed'], ['storage_unavailable', 'Upload failed'], ['', 'Upload failed']]) {
      const { instance } = tray({ uploadAttachment: vi.fn().mockRejectedValue(code === '' ? new Error('net') : { code }) });
      await instance.addFiles([file('a.md')]);
      expect(instance.rejected).toBe(true);
      expect(instance.element.querySelector('.nowo-chat-file__state')?.textContent).toBe(text);
    }
  });

  it('refreshes states from the server and waits until ready (send when ready)', async () => {
    vi.useFakeTimers();
    const drafts = vi.fn().mockResolvedValueOnce([stub({ uuid: 'u-a.md', state: 'scanning' })]).mockRejectedValueOnce(new Error('net')).mockResolvedValue([stub({ uuid: 'u-a.md', state: 'clean' })]);
    const { instance } = tray({ attachmentDrafts: drafts });
    await instance.refresh();
    expect(drafts).not.toHaveBeenCalled();
    await instance.addFiles([file('a.md')]);
    const waiting = instance.waitUntilReady(60_000, 1000);
    expect(instance.element.querySelector('[role="status"]')?.textContent).toBe('Waiting');
    await vi.advanceTimersByTimeAsync(3000);
    await expect(waiting).resolves.toBe(true);
    expect(instance.element.querySelector('[role="status"]')?.textContent).toBe('');
    // Blocked meanwhile: the wait ends at once with false.
    const blocked = tray({ attachmentDrafts: vi.fn().mockResolvedValue([stub({ uuid: 'u-b.md', state: 'blocked' })]) });
    await blocked.instance.addFiles([file('b.md')]);
    const refused = blocked.instance.waitUntilReady(60_000, 1000);
    await vi.advanceTimersByTimeAsync(1000);
    await expect(refused).resolves.toBe(false);
    expect(blocked.instance.rejected).toBe(true);
    // Times out.
    const slow = tray();
    await slow.instance.addFiles([file('c.md')]);
    const timeout = slow.instance.waitUntilReady(2000, 1000);
    await vi.advanceTimersByTimeAsync(3000);
    await expect(timeout).resolves.toBe(false);
  });

  it('removes a chip: focus moves on, the draft is removed on the server (a failure is ignored)', async () => {
    const remove = vi.fn().mockRejectedValueOnce(new Error('gone')).mockResolvedValue(undefined);
    const { instance, focusAfterRemove } = tray({ removeAttachment: remove });
    await instance.addFiles([file('a.md'), file('b.md')]);
    const buttons = () => [...instance.element.querySelectorAll<HTMLButtonElement>('.nowo-chat-file__remove')];
    expect(buttons()[0]?.getAttribute('aria-label')).toBe('Remove: a.md');
    buttons()[0]?.click();
    await Promise.resolve();
    expect(document.activeElement).toBe(buttons()[0]);
    buttons()[0]?.click();
    await vi.waitFor(() => expect(remove).toHaveBeenCalledTimes(2));
    expect(focusAfterRemove).toHaveBeenCalled();
    expect(instance.hasFiles).toBe(false);
    // A chip whose upload failed is removed locally only.
    const failed = tray({ uploadAttachment: vi.fn().mockRejectedValue({ code: 'x' }) });
    await failed.instance.addFiles([file('c.md')]);
    (failed.instance.element.querySelector('.nowo-chat-file__remove') as HTMLButtonElement).click();
    expect(failed.calls.removeAttachment).not.toHaveBeenCalled();
  });

  it('renders file cards as text and downloads through a URL signed at click time', async () => {
    const download = vi.fn();
    const list = renderFiles([stub({ name: '<img src=x onerror=alert(1)>.md', size: 4096 })], i18n, icon, download);
    expect(list.querySelector('img')).toBeNull();
    expect(list.querySelector('.nowo-chat-file__name')?.textContent).toBe('<img src=x onerror=alert(1)>.md');
    expect(list.querySelector('.nowo-chat-file__size')?.textContent).toBe('4 KB');
    (list.querySelector('.nowo-chat-file__download') as HTMLButtonElement).click();
    expect(download).toHaveBeenCalledWith('a1');

    const follow = vi.fn();
    const api = { attachment: vi.fn().mockResolvedValueOnce({ attachment: stub(), url: '/chat/files/a1/original?e=1&s=x' }).mockResolvedValueOnce({ attachment: stub(), url: null }).mockResolvedValueOnce({ attachment: stub(), url: 'https://evil.test/x' }) } as unknown as AttachmentApi;
    await expect(downloadFile(api, 'a1', follow)).resolves.toBe(true);
    expect(follow).toHaveBeenCalledWith('/chat/files/a1/original?e=1&s=x');
    await expect(downloadFile(api, 'a1', follow)).resolves.toBe(false);
    await expect(downloadFile(api, 'a1', follow)).resolves.toBe(false);
    expect(follow).toHaveBeenCalledTimes(1);

    const clicked = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    followDownload('/chat/files/a1/original?e=1&s=x');
    expect(clicked).toHaveBeenCalled();
    expect(document.querySelector('a[download]')).toBeNull();
  });
});
