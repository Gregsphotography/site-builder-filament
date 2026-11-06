import test from 'node:test';
import assert from 'node:assert/strict';
import { copyStructuredText, readStructuredText, handlesDocumentHistory } from '../resources/js/clipboard.js';

test('structured copy and paste retain usable manual fallbacks without secure-context APIs', async () => {
  const unavailable = await copyStructuredText('{"version":1}', {});
  assert.equal(unavailable.ok, false);
  assert.match(unavailable.message, /Select the structured text/);
  const denied = await copyStructuredText('retained text', { writeText: async () => { throw new Error('denied'); } });
  assert.equal(denied.ok, false);
  assert.match(denied.message, /browser did not allow/);
  let copied;
  assert.equal((await copyStructuredText('exact structured text', { writeText: async value => { copied = value; } })).ok, true);
  assert.equal(copied, 'exact structured text');
  assert.match((await readStructuredText({})).message, /Paste the structured text/);
  assert.equal((await readStructuredText({ readText: async () => 'exact pasted text' })).text, 'exact pasted text');
});

test('document history shortcuts respect native input undo, active controls and modal boundaries', () => {
  const event = { key: 'z', metaKey: true, ctrlKey: false, shiftKey: false, altKey: false, target: { closest: () => null } };
  assert.equal(handlesDocumentHistory(event), 'undoDraft');
  assert.equal(handlesDocumentHistory({ ...event, shiftKey: true }), 'redoDraft');
  assert.equal(handlesDocumentHistory({ ...event, key: 'y', metaKey: false, ctrlKey: true }), 'redoDraft');
  for (const target of [{ isContentEditable: true }, { closest: () => ({}) }]) assert.equal(handlesDocumentHistory({ ...event, target }), null);
  for (const state of [{ blocked: true }, { busy: true }, { modalOpen: true }]) assert.equal(handlesDocumentHistory(event, state), null);
  assert.equal(handlesDocumentHistory({ ...event, altKey: true }), null);
  assert.equal(handlesDocumentHistory({ ...event, defaultPrevented: true }), null);
});

test('typing UUID fallback works on ordinary HTTP origins', async () => {
  const { newTypingGroup } = await import('../resources/js/clipboard.js');
  const first = newTypingGroup(), second = newTypingGroup();
  assert.match(first, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  assert.notEqual(first, second);
});

test('history cursor labels change only after matching queue acknowledgement including accepted partial flushes', async () => {
  const { default: editor } = await import('../resources/js/site-builder.js');
  const { WriteQueue } = await import('../resources/js/write-queue.js');
  const state = editor({});
  state.$wire = { formData: {}, formBaseline: {}, designData: {}, designBaseline: {}, readOnly: false, previewUrl: '', draftHistoryState: { undoCount: 99 } };
  state.$refs = {};
  state.$nextTick = callback => callback();
  state.historyState = { undoCount: 1, canUndo: true };
  state.queue = new WriteQueue(async () => ({ ok: true, requestId: 99, version: 2, history: { undoCount: 99 } }));
  await state.run('undoDraft');
  assert.equal(state.historyState.undoCount, 1, 'a malformed acknowledgement cannot morph trusted cursor labels');
  assert.equal(state.queue.blocked, true);
  state.queue = new WriteQueue(async (_action, requestId) => ({ ok: false, requestId, version: 2, state: 'invalid', message: 'Publication is invalid after accepted draft flush.', history: { undoCount: 2, canUndo: true } }));
  await state.run('publish');
  assert.equal(state.historyState.undoCount, 2, 'a matching validation result can confirm an earlier accepted draft flush');
  state.queue = new WriteQueue(async (_action, requestId) => ({ ok: false, requestId, version: 3, state: 'conflict', history: { undoCount: 999 } }));
  await state.run('undoDraft');
  assert.equal(state.historyState.undoCount, 2);
});

test('autosave keeps an uninterrupted typing UUID and rotates it after a native focus boundary', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const { default: factory } = await import('../resources/js/site-builder.js');
  const { WriteQueue } = await import('../resources/js/write-queue.js');
  const state = factory({});
  state.$wire = { formData: { heading: 'Original' }, formBaseline: { heading: 'Original' }, designData: {}, designBaseline: {}, readOnly: false, previewUrl: '' };
  state.$refs = {};
  state.$nextTick = callback => callback();
  const requests = [];
  state.queue = new WriteQueue(async (action, group, requestId) => {
    requests.push({ action, group });
    state.$wire.formBaseline = { ...state.$wire.formData };
    return { ok: true, requestId, version: requests.length };
  });
  for (const heading of ['First', 'Second']) {
    state.$wire.formData.heading = heading;
    state.changed();
    t.mock.timers.tick(650);
    await new Promise(resolve => setImmediate(resolve));
  }
  assert.equal(requests.length, 2);
  assert.equal(requests[0].action, 'autosaveSelected');
  assert.equal(requests[0].group, requests[1].group);
  state.typingBoundary = true;
  state.$wire.formData.heading = 'After focus changed';
  state.changed();
  t.mock.timers.tick(650);
  await new Promise(resolve => setImmediate(resolve));
  assert.notEqual(requests[2].group, requests[1].group);
});

test('recovery downloads retain copy input while excluding editor/history authority', async () => {
  const { default: factory } = await import('../resources/js/site-builder.js');
  const previousDocument = globalThis.document, previousURL = globalThis.URL;
  let artifact;
  globalThis.URL = { createObjectURL: blob => { artifact = blob; return 'blob:recovery'; }, revokeObjectURL: () => {} };
  globalThis.document = { createElement: () => ({ click: () => {} }) };
  try {
    const state = factory({ pageId: 42, csrf: 'csrf-secret', session: 'tab-secret' });
    state.$wire = { documentJson: '{"version":1,"nodes":[]}', writeVersion: 4, locale: 'en', leaseToken: 'lease-secret', channel: 'channel-secret', tabId: 'tab-secret', draftHistoryContextData: { id: 'history-id', epoch: 'epoch-secret' }, clipboardText: '{"authored":"retained text"}', clipboardReferenceValues: {}, formData: {}, settingsData: {}, designData: {} };
    state.download();
    const text = await artifact.text();
    for (const secret of ['csrf-secret', 'tab-secret', 'lease-secret', 'channel-secret', 'epoch-secret', 'history-id']) assert.equal(text.includes(secret), false);
    assert.equal(JSON.parse(text).clipboard.text, '{"authored":"retained text"}');
  } finally {
    globalThis.document = previousDocument;
    globalThis.URL = previousURL;
  }
});

test('readonly copied output does not warn about unsaved page edits and blocked paste input can be cleared locally', async () => {
  const { default: factory } = await import('../resources/js/site-builder.js');
  const state = factory({});
  state.$wire = { copiedSectionText: '{"immutable":"copy"}', clipboardText: '' };
  assert.equal(state.clipboardInputPending(), false);
  state.$wire.clipboardText = '{"pasted":"retained"}';
  state.clipboardCheckedText = state.$wire.clipboardText;
  assert.equal(state.clipboardInputPending(), true);
  state.queue = { blocked: true };
  state.clearPastedContent();
  assert.equal(state.$wire.clipboardText, '');
  assert.equal(state.clipboardCheckedText, '');
  assert.match(state.clipboardMessage, /cleared locally/);
});
