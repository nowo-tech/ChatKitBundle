/** IIFE build entry (`nowo-chat.js`): exposes `window.NowoChat` and starts the dock automatically. */
import { autoStart } from './index';
import { createBundleLogger } from './logger';

declare const __CHAT_KIT_BUILD_TIME__: string;

createBundleLogger('chat-kit', {
  buildTime: typeof __CHAT_KIT_BUILD_TIME__ !== 'undefined' ? __CHAT_KIT_BUILD_TIME__ : undefined,
}).scriptLoaded();

export * from './index';

autoStart();
