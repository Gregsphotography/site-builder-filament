// Browser clipboard permission is optional; plain text remains visible for HTTP hosts.
export async function copyStructuredText(text, clipboard = globalThis.navigator?.clipboard) {
  if (typeof clipboard?.writeText !== 'function') return { ok: false, message: 'Automatic copy is unavailable here. Select the structured text below and copy it, or download the file.' };
  try {
    await clipboard.writeText(text);
    return { ok: true, message: 'Structured section copied. Paste it into another authorized page on this installation before it expires.' };
  } catch {
    return { ok: false, message: 'The browser did not allow automatic copy. Select the text below and copy it, or download the file.' };
  }
}

export async function readStructuredText(clipboard = globalThis.navigator?.clipboard) {
  if (typeof clipboard?.readText !== 'function') return { ok: false, message: 'Automatic clipboard reading is unavailable here. Paste the structured text into the field below.' };
  try {
    return { ok: true, text: await clipboard.readText(), message: 'Clipboard text loaded. Review it before inserting.' };
  } catch {
    return { ok: false, message: 'The browser did not allow clipboard access. Paste the structured text into the field below.' };
  }
}

export function handlesDocumentHistory(event, { blocked = false, busy = false, modalOpen = false } = {}) {
  if (blocked || busy || modalOpen || event.defaultPrevented || event.altKey || !(event.ctrlKey || event.metaKey)) return null;
  const target = event.target;
  // Rich editors and native controls keep their own undo and redo behavior.
  if (target?.isContentEditable || target?.closest?.('input,textarea,select,button,a[href],[contenteditable="true"],[role="textbox"],[role="combobox"],[role="listbox"]')) return null;
  const key = event.key.toLowerCase();
  if (key === 'z') return event.shiftKey ? 'redoDraft' : 'undoDraft';
  if (key === 'y' && event.ctrlKey && !event.shiftKey) return 'redoDraft';
  return null;
}


// Group IDs are display/coalescing hints, never authorization capabilities.
export function newTypingGroup() {
  return globalThis.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, letter => {
    const value = Math.floor(Math.random() * 16);
    return (letter === 'x' ? value : ((value & 3) | 8)).toString(16);
  });
}
