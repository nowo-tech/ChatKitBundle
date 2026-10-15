/** IIFE build entry (`nowo-chat.js`): exposes `window.NowoChat` and starts the dock automatically. */
import { autoStart } from './index';

export * from './index';

autoStart();
