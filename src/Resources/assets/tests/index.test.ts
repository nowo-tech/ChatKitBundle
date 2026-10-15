import { describe, expect, it } from 'vitest';
import { CSS_TOKEN_PREFIX, VERSION, cssToken } from '../src/index';

describe('nowo-chat entry', () => {
  it('exposes the version and the token prefix', () => {
    expect(VERSION).toMatch(/^\d+\.\d+\.\d+/);
    expect(CSS_TOKEN_PREFIX).toBe('--nowo-chat-');
  });

  it('builds theme token names', () => {
    expect(cssToken('accent')).toBe('--nowo-chat-accent');
    expect(cssToken('  --bubble-radius ')).toBe('--nowo-chat-bubble-radius');
  });

  it('rejects empty token names', () => {
    expect(() => cssToken('  ')).toThrow('must not be empty');
  });
});
