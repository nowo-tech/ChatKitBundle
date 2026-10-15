/**
 * Demo app entry (Pentatrion Vite + TypeScript).
 * Compiles the NowoChatKitBundle browser entry from the mounted bundle sources.
 */
import { cssToken } from '@bundle/src/index.ts';

const el = document.getElementById('nowo-chat-token');
if (el instanceof HTMLElement) {
  el.textContent = cssToken('accent');
}
