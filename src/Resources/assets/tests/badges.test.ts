import { describe, expect, it } from 'vitest';
import { appendAuthorBadges, AUTHOR_BADGE_VARIANTS, authorBadges } from '../src/badges';
import { templatesRoot } from './support';

const SCRIPT = '<script>alert(1)</script>';
const IMG = '"><img src=x onerror=alert(1)>';

describe('authorBadges', () => {
  it('keeps well-formed badges with an allow-listed variant', () => {
    expect(authorBadges([{ label: 'Agent', variant: 'agent', title: 'API token' }, { label: 'Guest', variant: 'neutral' }])).toEqual([
      { label: 'Agent', variant: 'agent', title: 'API token' },
      { label: 'Guest', variant: 'neutral', title: null },
    ]);
    expect(AUTHOR_BADGE_VARIANTS).toEqual(['neutral', 'info', 'success', 'warning', 'agent']);
  });

  it('gives nothing for an older server or a malformed payload', () => {
    expect(authorBadges(undefined)).toEqual([]);
    expect(authorBadges(null)).toEqual([]);
    expect(authorBadges('Agent')).toEqual([]);
    expect(authorBadges({ label: 'Agent', variant: 'agent' })).toEqual([]);
    expect(
      authorBadges([
        null,
        'Agent',
        { variant: 'agent' },
        { label: '   ', variant: 'agent' },
        { label: 'a'.repeat(41), variant: 'agent' },
        { label: 'Agent', variant: 'danger' },
        { label: 'Agent', variant: 'agent bg-danger' },
        { label: 'Agent' },
        { label: 42, variant: 'agent' },
      ]),
    ).toEqual([]);
  });

  it('counts characters, not UTF-16 units, and drops an oversized or non-string title', () => {
    expect(authorBadges([{ label: '🤖'.repeat(40), variant: 'agent' }])).toHaveLength(1);
    expect(authorBadges([{ label: 'Agent', variant: 'agent', title: 't'.repeat(121) }])[0]?.title).toBeNull();
    expect(authorBadges([{ label: 'Agent', variant: 'agent', title: 7 }])[0]?.title).toBeNull();
    expect(authorBadges([{ label: 'Agent', variant: 'agent', title: '' }])[0]?.title).toBeNull();
  });
});

describe('appendAuthorBadges', () => {
  it('renders a script label and a quote-breaking title as text, never as markup', () => {
    const author = document.createElement('p');
    author.textContent = 'Deploy bot';
    appendAuthorBadges(author, authorBadges([{ label: SCRIPT, variant: 'agent', title: IMG }]), document.createElement('div'));

    expect(author.querySelector('script')).toBeNull();
    expect(author.querySelector('img')).toBeNull();
    const badge = author.querySelector<HTMLElement>('.nowo-chat-author-badge') as HTMLElement;
    expect(badge.textContent).toBe(SCRIPT);
    expect(badge.getAttribute('title')).toBe(IMG);
    expect(badge.className).toBe('nowo-chat-author-badge nowo-chat-author-badge--agent');
    expect(badge.dataset.variant).toBe('agent');
    expect(author.textContent).toBe(`Deploy bot ${SCRIPT}`);
    expect(author.innerHTML).toContain('&lt;script&gt;');
  });

  it('fills the dock template of the page when it has one (rendered with a provider)', () => {
    const root = templatesRoot();
    root.insertAdjacentHTML('beforeend', '<template data-nowo-chat-template="author-badge"><span class="nowo-chat-author-badge" data-part="label"></span></template>');
    const author = document.createElement('p');
    appendAuthorBadges(author, [{ label: 'Agent', variant: 'agent', title: null }, { label: 'Beta', variant: 'warning', title: 'Preview' }], root);

    const badges = author.querySelectorAll<HTMLElement>('.nowo-chat-author-badge');
    expect([...badges].map((badge) => badge.textContent)).toEqual(['Agent', 'Beta']);
    expect(badges[0]?.hasAttribute('title')).toBe(false);
    expect(badges[1]?.getAttribute('title')).toBe('Preview');
    expect(badges[1]?.classList.contains('nowo-chat-author-badge--warning')).toBe(true);
  });

  it('follows a host template override (REQ-TWIG-001) and still sets the label as text', () => {
    const root = document.createElement('div');
    root.innerHTML = '<template data-nowo-chat-template="author-badge"><em class="pill"><b data-part="label"></b><i>bot</i></em></template>';
    const author = document.createElement('p');
    appendAuthorBadges(author, [{ label: SCRIPT, variant: 'info', title: null }], root);

    const pill = author.querySelector<HTMLElement>('em.pill') as HTMLElement;
    expect(pill.classList.contains('nowo-chat-author-badge--info')).toBe(true);
    expect(pill.querySelector('b')?.textContent).toBe(SCRIPT);
    expect(pill.querySelector('i')?.textContent).toBe('bot');
    expect(author.querySelector('script')).toBeNull();
  });

  it('builds a plain span when the page has no template (a host without a provider, an older override)', () => {
    const author = document.createElement('p');
    appendAuthorBadges(author, [{ label: 'Agent', variant: 'agent', title: null }], templatesRoot());
    expect(author.innerHTML).toBe(' <span class="nowo-chat-author-badge nowo-chat-author-badge--agent" data-variant="agent">Agent</span>');
  });
});
