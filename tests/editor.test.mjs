import test from 'node:test';
import assert from 'node:assert/strict';
import { WriteQueue, stableState } from '../resources/js/write-queue.js';
import { validMessage, previewMessage } from '#site-builder-core-protocol';
import { captureEditorFocus } from '../resources/js/focus.js';

const source = {};
const expected = { origin: 'https://demo.test', source, pageId: 7, locale: 'en', session: 'tab-identity', channel: 'channel-identity' };
const select = previewMessage(expected, 'select', { nodeId: 'stable-node' });
test('preview messages require exact origin, source, protocol, scope and payload shape', () => {
  const event = { origin: expected.origin, source, data: select };
  assert.equal(validMessage(event, expected, ['select']), true);
  for (const bad of [{ origin: 'https://evil.test' }, { source: {} }, { data: { ...select, version: 2 } }, { data: { ...select, pageId: 8 } }, { data: { ...select, locale: 'de' } }, { data: { ...select, session: 'another-tab' } }, { data: { ...select, channel: 'old-frame' } }, { data: { ...select, nodeId: 'x'.repeat(129) } }, { data: { ...select, script: 'anything' } }, { data: { ...select, type: 'publish' } }, { data: [] }]) {
    assert.equal(validMessage({ ...event, ...bad }, expected, ['select']), false);
  }
  assert.equal(validMessage({ origin: expected.origin, source, data: previewMessage(expected, 'highlight', { nodeId: null }) }, expected, ['highlight']), true);
});

test('queued writes wait for acknowledgement and publish sees the accepted version', async () => {
  let resolve;
  const sent = [];
  const queue = new WriteQueue((action, requestId) => {
    sent.push([action, requestId]);
    if (action === 'save') return new Promise(r => { resolve = r; });
    assert.equal(queue.version, 1);
    return { ok: true, requestId, version: 2 };
  });
  const save = queue.enqueue('save');
  const publish = queue.enqueue('publish');
  await new Promise(r => setTimeout(r, 0));
  assert.deepEqual(sent, [['save', 1]]);
  resolve({ ok: true, requestId: 1, version: 1 });
  await save; await publish;
  assert.deepEqual(sent, [['save', 1], ['publish', 2]]);
  assert.equal(queue.lastAck, 2);
});

test('a lost acknowledgement blocks retries until explicit reconciliation', async () => {
  let serverVersion = 0;
  let sends = 0;
  const queue = new WriteQueue((action, requestId) => {
    sends++;
    if (action === 'save') { serverVersion++; throw new Error('Network lost after server commit'); }
    return { ok: true, requestId, version: serverVersion };
  });
  await assert.rejects(queue.enqueue('save'));
  await assert.rejects(queue.enqueue('save'));
  assert.equal(sends, 1);
  await queue.enqueue('reloadSaved');
  assert.equal(queue.version, 1);
  assert.equal(queue.blocked, false);
});

test('old or malformed acknowledgements are never accepted', async () => {
  for (const response of [{ ok: true, requestId: 0, version: 9 }, { ok: true, requestId: 1, version: -1 }, undefined]) {
    const queue = new WriteQueue(() => response);
    await assert.rejects(queue.enqueue('save'));
    assert.equal(queue.lastAck, 0);
    assert.equal(queue.version, 0);
    assert.equal(queue.blocked, true);
  }
});

test('raw repeater UUID key order is meaningful before dehydration', () => {
  assert.notEqual(stableState({first: {label: 'One'}, second: {label: 'Two'}}), stableState({second: {label: 'Two'}, first: {label: 'One'}}));
});

test('distributed preview guard selects interactive sections while suppressing links and form submission', async () => {
  const { readFile } = await import('node:fs/promises');
  const { runInNewContext } = await import('node:vm');
  const listeners = {};
  const posted = [];
  const node = { dataset: { siteBuilderNode: 'cta' }, style: {} };
  class Element { closest(selector) { return selector === '[data-site-builder-node]' ? node : this; } }
  const parent = { postMessage: (message, origin) => posted.push({ message, origin }) };
  const scope = { pageId: 7, locale: 'en', session: 'test-session', channel: 'test-channel', version: 1, protocol: 'site-builder.preview' };
  const window = { parent, location: { origin: 'https://demo.test' }, addEventListener: (type, callback) => { listeners[type] = callback; } };
  const document = { addEventListener: (type, callback) => { listeners[type] = callback; }, getElementById: () => ({textContent: JSON.stringify(scope)}), querySelectorAll: selector => selector === '[data-site-builder-node]' ? [node] : [] };
  runInNewContext(await readFile(new URL('../vendor/gregsphotography/site-builder-core/resources/dist/preview.js', import.meta.url), 'utf8'), { window, document, Element });
  assert.equal(posted[0].message.type, 'ready');
  let prevented = 0; let stopped = 0;
  const event = {target: new Element(), preventDefault: () => prevented++, stopImmediatePropagation: () => stopped++};
  listeners.click(event);
  assert.equal(posted.at(-1).message.nodeId, 'cta');
  assert.equal(posted.at(-1).origin, 'https://demo.test');
  assert.equal(prevented, 1); assert.equal(stopped, 1);
  listeners.submit(event);
  assert.equal(prevented, 2); assert.equal(stopped, 2);
  const highlight = previewMessage(scope, 'highlight', { nodeId: 'cta' });
  listeners.message({origin: 'https://evil.test', source: parent, data: highlight});
  assert.equal(node.style.outline, undefined);
  listeners.message({origin: 'https://demo.test', source: {}, data: highlight});
  assert.equal(node.style.outline, undefined);
  listeners.message({origin: 'https://demo.test', source: parent, data: highlight});
  assert.match(node.style.outline, /2px/);
});

test('autosave coalesces edits and a late acknowledgement leaves newer input queued', async t => {
  const { default: factory } = await import('../resources/js/site-builder.js');
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const editor = factory({});
  const requests = [];
  editor.$wire = { formData: { heading: 'Original' }, formBaseline: { heading: 'Original' }, readOnly: false };
  editor.highlight = () => {};
  editor.queue = { blocked: false, enqueue: () => new Promise(resolve => {
    const accepted = structuredClone(editor.$wire.formData);
    requests.push(() => { editor.$wire.formBaseline = accepted; resolve({ok:true}); });
  }) };
  editor.$wire.formData.heading = 'First'; editor.changed();
  editor.$wire.formData.heading = 'Second'; editor.changed();
  t.mock.timers.tick(650);
  assert.equal(requests.length, 1);
  editor.$wire.formData.heading = 'Newer while saving'; editor.changed();
  requests[0]();
  await Promise.resolve(); await Promise.resolve();
  assert.equal(editor.dirty, true);
  t.mock.timers.tick(650);
  assert.equal(requests.length, 2);
  requests[1]();
  await Promise.resolve(); await Promise.resolve();
  assert.equal(editor.dirty, false);
  assert.equal(editor.$wire.formData.heading, 'Newer while saving');
});

function focusFixture() {
  const focused = [];
  const element = name => ({ isConnected: true, disabled: false, getClientRects: () => [{}], focus: options => focused.push({name, options}), closest: () => null });
  const button = element('button');
  const document = { body: {}, activeElement: button };
  const root = { isConnected: true, contains: target => target === button, querySelector: () => null };
  return { focused, element, button, document, root };
}

test('acknowledged editor actions restore keyboard focus to their control', () => {
  const fixture = focusFixture();
  const restore = captureEditorFocus(fixture.root, fixture.document);
  fixture.document.activeElement = fixture.document.body;
  restore();
  assert.deepEqual(fixture.focused, [{name:'button', options:{preventScroll:true}}]);
});

test('removed or disabled controls fall back to a visible section or recovery control', () => {
  const fixture = focusFixture();
  const section = fixture.element('section');
  const recovery = fixture.element('recovery');
  fixture.button.closest = () => ({querySelector: () => section});
  fixture.root.querySelector = selector => selector.startsWith('.sb-notice') ? recovery : null;
  const restore = captureEditorFocus(fixture.root, fixture.document);
  fixture.document.activeElement = fixture.document.body;
  fixture.button.isConnected = false;
  restore();
  assert.equal(fixture.focused.at(-1).name, 'section');
  section.getClientRects = () => [];
  restore();
  assert.equal(fixture.focused.at(-1).name, 'recovery');
});

test('late responses do not steal focus from another input or a detached editor', () => {
  const fixture = focusFixture();
  const restore = captureEditorFocus(fixture.root, fixture.document);
  fixture.document.activeElement = fixture.element('new input');
  restore();
  fixture.document.activeElement = fixture.document.body;
  fixture.root.isConnected = false;
  restore();
  assert.deepEqual(fixture.focused, []);
  assert.equal(captureEditorFocus(fixture.root, {body:fixture.button, activeElement:fixture.button}), null);
});

test('saved requests can navigate while unsaved or unconfirmed input remains guarded', async () => {
  const { default: factory } = await import('../resources/js/site-builder.js');
  const previousWindow = globalThis.window;
  const listeners = {};
  globalThis.window = {addEventListener: (type, listener) => { listeners[type] = listener; }, removeEventListener: () => {}};
  const editor = factory({heartbeat:30});
  editor.$watch = () => {};
  editor.$wire = {writeVersion:0, settingsData:{title:'Saved'}, settingsBaseline:{title:'Saved'}, $interceptMessage:() => () => {}};
  try {
    editor.init();
    editor.busy = true; // A successful delete redirects before the action promise settles.
    let prevented = 0;
    const event = {preventDefault:() => prevented++};
    listeners.beforeunload(event);
    assert.equal(prevented, 0);
    editor.dirty = true;
    listeners.beforeunload(event);
    editor.dirty = false;
    editor.$wire.settingsData.title = 'Unsaved metadata';
    listeners.beforeunload(event);
    editor.$wire.settingsData.title = 'Saved';
    editor.queue.blocked = true;
    listeners.beforeunload(event);
    assert.equal(prevented, 3);
    assert.equal(event.returnValue, '');
  } finally {
    editor.destroy();
    if (previousWindow === undefined) delete globalThis.window;
    else globalThis.window = previousWindow;
  }
});
