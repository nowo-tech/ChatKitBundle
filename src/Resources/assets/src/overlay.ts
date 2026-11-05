/**
 * 1.18: a modal overlay a host opens itself (a right-edge drawer such as Beacon's `EdgeDrawer`, a host modal) tells the
 * floating dock to step aside through the overlay contract the dock already follows (1.17, `dock.overlay`):
 * `nowo:overlay-open` / `nowo:overlay-close` on `document` with `detail: { depth, kind, owner }` (`depth` after the
 * change) and `<html data-nowo-overlay="<depth>">` while depth > 0.
 *
 * `acquireOverlay(owner, options)` returns a handle; `handle.release()` undoes it (idempotent). With UiKit's registry on
 * the page (`window.NowoUiOverlay`, UiKit 2.28+ `js/nowo-ui-overlay.js`, also inside `nowo-ui-dialog.js` and
 * `nowo-ui-side-panel.js`) the handle is UiKit's, so UiKit dialogs and host drawers share one depth. Without it, a small
 * registry of the chat keeps the depth of the overlays acquired here and dispatches the same events (it never touches
 * the shell: `inertShell` needs UiKit).
 */

/** What opened the overlay (UiKit's kinds). */
export type ChatOverlayKind = 'dialog' | 'side-panel' | 'drawer' | 'host';

/** Options of {@link acquireOverlay}. */
export interface ChatOverlayOptions {
  /** Default `drawer`. */
  kind?: ChatOverlayKind;
  /** UiKit only: make the shell regions inert while the overlay is open (default false: the host keeps its own). */
  inertShell?: boolean;
}

/** A live overlay: release it when the overlay closes. */
export interface ChatOverlayHandle {
  readonly kind: ChatOverlayKind;
  readonly owner: Element;
  /** Whether it still counts. */
  active(): boolean;
  /** Ends it (idempotent): the dock comes back once no overlay is left. */
  release(): void;
}

/** The part of UiKit's `window.NowoUiOverlay` registry used here. */
interface UiOverlayRegistry {
  acquire(owner: Element, options?: { kind?: ChatOverlayKind; inertShell?: boolean }): ChatOverlayHandle;
}

export const OVERLAY_OPEN_EVENT = 'nowo:overlay-open';
export const OVERLAY_CLOSE_EVENT = 'nowo:overlay-close';
export const OVERLAY_ROOT_ATTR = 'data-nowo-overlay';

/** The overlays acquired through the chat's own registry (no UiKit on the page). */
const local = new Set<ChatOverlayHandle>();

/** Publishes the depth after a change of the chat's own registry. */
function announce(type: string, kind: ChatOverlayKind, owner: Element): void {
  const depth = local.size;
  if (depth > 0) {
    document.documentElement.setAttribute(OVERLAY_ROOT_ATTR, String(depth));
  } else {
    document.documentElement.removeAttribute(OVERLAY_ROOT_ATTR);
  }
  document.dispatchEvent(new CustomEvent(type, { detail: { depth, kind, owner } }));
}

/**
 * Marks a modal overlay as open until the returned handle is released.
 *
 * @param owner The overlay element (the drawer, the modal).
 * @param options Kind (default `drawer`) and, with UiKit, `inertShell` (default false).
 * @returns The handle.
 */
export function acquireOverlay(owner: Element, options: ChatOverlayOptions = {}): ChatOverlayHandle {
  const kind = options.kind ?? 'drawer';
  const registry = (window as unknown as { NowoUiOverlay?: UiOverlayRegistry }).NowoUiOverlay;
  if (registry !== undefined && typeof registry.acquire === 'function') {
    return registry.acquire(owner, { kind, inertShell: options.inertShell ?? false });
  }
  let live = true;
  const handle: ChatOverlayHandle = {
    kind,
    owner,
    active: () => live,
    release: () => {
      if (!live) {
        return;
      }
      live = false;
      local.delete(handle);
      announce(OVERLAY_CLOSE_EVENT, kind, owner);
    },
  };
  local.add(handle);
  announce(OVERLAY_OPEN_EVENT, kind, owner);
  return handle;
}
