/** Test helpers: the real <template> markup rendered by `_templates.html.twig` (see DockTemplatesFixtureTest). */
/// <reference types="vite/client" />
import { Templates } from '../src/templates';
import TEMPLATES from './fixtures/templates.html?raw';

export const TEMPLATES_HTML: string = TEMPLATES;

/** A detached root holding the templates, like the `.nowo-chat` element of the page. */
export function templatesRoot(): HTMLElement {
  const root = document.createElement('div');
  root.className = 'nowo-chat';
  root.innerHTML = TEMPLATES_HTML;
  return root;
}

export function templates(): Templates {
  return new Templates(templatesRoot());
}
