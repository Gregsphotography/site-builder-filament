// Disabling a focused control during a request moves browser focus to the body.
export function captureEditorFocus(root, document) {
  const previous = document.activeElement;
  if (!previous || previous === document.body || !root.contains(previous)) return null;
  const row = previous.closest('.sb-sections > li');

  return () => {
    if (!root.isConnected || document.activeElement !== document.body) return;
    const candidates = [
      previous,
      row?.querySelector('.sb-section-select'),
      root.querySelector('.sb-notice button:not([disabled])'),
      root.querySelector('.sb-section-select[aria-pressed="true"]'),
      root.querySelector('.sb-tabs button[aria-pressed="true"]'),
    ];
    const target = candidates.find(element => element?.isConnected && !element.disabled && element.getClientRects().length);
    target?.focus({ preventScroll: true });
  };
}

// Livewire can resolve a call before the new row and its enabled state finish morphing.
export function focusAcceptedSection(root, document, nodeId, options = {}) {
  return waitForEditorFocus(root, document, currentRoot => {
    const row = [...currentRoot.querySelectorAll('[data-node-id]')].find(element => element.dataset.nodeId === nodeId);
    return row?.querySelector('.sb-section-select');
  }, options);
}

export function focusEditorControl(root, document, selector, options = {}) {
  return waitForEditorFocus(root, document, currentRoot => {
    for (const candidate of Array.isArray(selector) ? selector : [selector]) {
      const target = currentRoot.querySelector(candidate);
      if (target?.getClientRects().length) return target;
    }
    return null;
  }, options);
}

function waitForEditorFocus(root, document, find, { schedule = callback => requestAnimationFrame(callback), isCurrent = () => true, attempts = 12, allowFrom = null } = {}) {
  if (!document) return;
  const tryFocus = remaining => {
    if (!isCurrent()) return;
    const currentRoot = resolveEditorRoot(root, document);
    if (!currentRoot) { if (remaining > 0) schedule(() => tryFocus(remaining - 1)); return; }
    const active = document.activeElement;
    if (active && active !== document.body && active !== allowFrom && !active.disabled && active.isConnected && active.getClientRects().length && !active.closest('.sb-picker-overlay')) return;
    const target = find(currentRoot);
    if (target?.isConnected && !target.disabled && target.getClientRects().length) {
      target.focus({ preventScroll: true });
      revealEditorControl(target);
      return;
    }
    if (remaining > 0) schedule(() => tryFocus(remaining - 1));
  };
  schedule(() => tryFocus(attempts));
}

function revealEditorControl(target) {
  const panel = target.closest?.('.sb-panel');
  if (!panel) return;
  const bounds = panel.getBoundingClientRect();
  const control = target.getBoundingClientRect();
  const top = bounds.top + panel.clientTop + 4;
  const bottom = bounds.top + panel.clientTop + panel.clientHeight - 4;
  if (control.top < top) panel.scrollTop += control.top - top;
  else if (control.bottom > bottom) panel.scrollTop += Math.min(control.bottom - bottom, control.top - top);
}

export function resolveEditorRoot(root, document) {
  if (!document) return null;
  if (root?.isConnected) return root;
  const identity = root?.dataset?.editorIdentity;
  if (!identity) return null;

  return [...document.querySelectorAll('.sb-editor[data-editor-identity]')].find(element => element.dataset.editorIdentity === identity) || null;
}
