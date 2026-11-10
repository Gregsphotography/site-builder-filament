import test from 'node:test';
import assert from 'node:assert/strict';
import editor from '../resources/js/site-builder.js';
import { WriteQueue } from '../resources/js/write-queue.js';
import { focusAcceptedSection } from '../resources/js/focus.js';

test('picker filters only shown authorized entries and keeps saved search on the server', () => {
  const state = editor({});
  const entries = [
    { dataset: { kind: 'component', category: 'Content', search: 'image and text story photo' } },
    { dataset: { kind: 'code-template', category: 'Page templates', search: 'about our team' } },
    { dataset: { kind: 'saved-section', category: 'Private', search: 'authorized result' } },
  ];
  state.$wire = { pickerCatalogueState: entries.map(entry => entry.dataset) };
  state.$refs = { pickerList: { querySelectorAll: () => [] } };
  state.pickerSearch = 'PHOTO';
  assert.equal(state.pickerCount(), 1);
  state.pickerCategory = 'Actions';
  assert.equal(state.pickerCount(), 0);
  state.pickerKind = 'saved-section';
  assert.equal(state.pickerCount(), 1);
  state.pickerKind = 'component';
  state.pickerCategory = '';
  state.$wire.pickerCatalogueState = entries.map(entry => ({ ...entry.dataset }));
  assert.equal(state.pickerCount(), 1, 'a server selection/morph keeps the shown catalogue count even when x-ref points at a detached empty list');
});

test('dialog keyboard focus cycles visible enabled controls in both directions', () => {
  const oldDocument = globalThis.document;
  let focused;
  const control = name => ({ disabled: false, getClientRects: () => [1], focus: () => { focused = name; } });
  const first = control('first'), disabled = { ...control('disabled'), disabled: true }, last = control('last');
  const state = editor({});
  state.$wire = { pickerOpen: true };
  state.$refs = { picker: { querySelectorAll: () => [first, disabled, last], contains: element => [first, disabled, last].includes(element) } };
  let prevented = 0;
  globalThis.document = { activeElement: last };
  state.trapPicker({ shiftKey: false, preventDefault: () => prevented++ });
  assert.equal(focused, 'first');
  globalThis.document.activeElement = first;
  state.trapPicker({ shiftKey: true, preventDefault: () => prevented++ });
  assert.equal(focused, 'last');
  assert.equal(prevented, 2);
  globalThis.document = oldDocument;
});

test('accepted inserted IDs select focus only after a matching acknowledgement', async () => {
  const oldDocument = globalThis.document, oldRaf = globalThis.requestAnimationFrame;
  globalThis.document = { body: {}, activeElement: null };
  const frames = [];
  globalThis.requestAnimationFrame = callback => frames.push(callback);
  const state = editor({});
  let focused = false, rendered = false;
  state.$wire = { formData: {}, formBaseline: {}, readOnly: false, previewUrl: '' };
  state.$refs = {};
  const target = { isConnected: true, disabled: false, getClientRects: () => [1], focus: () => { focused = true; } };
  state.$root = { isConnected: true, querySelectorAll: () => rendered ? [{ dataset: { nodeId: 'accepted-node' }, querySelector: () => target }] : [] };
  state.$nextTick = callback => callback();
  state.queue = new WriteQueue(async (_action, _replace, requestId) => {
    delete state.$root;
    return { ok: true, requestId, version: 1, insertedIds: ['accepted-node'], announcement: 'One section added.' };
  });
  await state.run('applyStartingPoint', false);
  assert.equal(focused, false, 'focus waits for the accepted row to finish morphing');
  frames.shift()();
  rendered = true;
  frames.shift()();
  assert.equal(focused, true);
  assert.equal(state.announcement, 'One section added.');
  focused = false;
  state.editorElement = { isConnected: true, querySelectorAll: () => [] };
  state.queue = new WriteQueue(async () => ({ ok: true, requestId: 99, version: 2, insertedIds: ['unconfirmed-node'] }));
  await state.run('applyStartingPoint', false);
  assert.equal(focused, false);
  assert.equal(state.queue.blocked, true);
  assert.match(state.error, /could not be confirmed/);
  globalThis.document = oldDocument;
  globalThis.requestAnimationFrame = oldRaf;
});

test('accepted focus resolves a replaced root and waits for enabled controls without stealing input focus', () => {
  const frames = [];
  let focused = false;
  const target = { isConnected: true, disabled: true, getClientRects: () => [1], focus: () => { focused = true; } };
  const replacement = { isConnected: true, dataset: { editorIdentity: 'page-tab' }, querySelectorAll: () => [{ dataset: { nodeId: 'new-section' }, querySelector: () => target }] };
  const original = { isConnected: false, dataset: { editorIdentity: 'page-tab' } };
  const document = { body: {}, activeElement: null, querySelectorAll: () => [replacement] };
  focusAcceptedSection(original, document, 'new-section', { schedule: callback => frames.push(callback) });
  frames.shift()();
  assert.equal(focused, false);
  target.disabled = false;
  frames.shift()();
  assert.equal(focused, true);
  focused = false;
  document.activeElement = { isConnected: true, getClientRects: () => [1], closest: () => null };
  focusAcceptedSection(original, document, 'new-section', { schedule: callback => frames.push(callback) });
  frames.shift()();
  assert.equal(focused, false);
});


test('accepted focus reveals only the clipped portion inside the active panel', () => {
  const frames = [];
  const panel = { scrollTop: 100, clientTop: 0, clientHeight: 300, getBoundingClientRect: () => ({top:80}) };
  let bounds = { top: 50, bottom: 92 };
  let focused = 0;
  const target = { isConnected: true, disabled: false, getClientRects: () => [1], focus: () => focused++, closest: selector => selector === '.sb-panel' ? panel : null, getBoundingClientRect: () => bounds };
  const root = { isConnected: true, querySelectorAll: () => [{dataset:{nodeId:'selected'},querySelector:() => target}] };
  const document = {body:{},activeElement:null};
  const reveal = () => { focusAcceptedSection(root, document, 'selected', {schedule: callback => frames.push(callback)}); frames.shift()(); };
  reveal();
  assert.equal(panel.scrollTop, 66);
  bounds = {top:350,bottom:392};
  reveal();
  assert.equal(panel.scrollTop, 82);
  bounds = {top:100,bottom:142};
  reveal();
  assert.equal(panel.scrollTop, 82);
  document.activeElement = {isConnected:true,getClientRects:()=>[1],closest:()=>null};
  bounds = {top:0,bottom:42};
  reveal();
  assert.equal(panel.scrollTop, 82);
  assert.equal(focused, 3);
});

test('accepted focus reveals a lower row in the independently scrolling section tree', () => {
  const frames = [];
  const tree = {scrollTop:0,clientTop:0,clientHeight:100,getBoundingClientRect:()=>({top:100})};
  const panel = {scrollTop:0,clientTop:0,clientHeight:700,getBoundingClientRect:()=>({top:80})};
  const target = {isConnected:true,disabled:false,getClientRects:()=>[1],focus:()=>{},closest:selector=>selector==='.sb-sections'?tree:panel,getBoundingClientRect:()=>({top:220-tree.scrollTop,bottom:262-tree.scrollTop})};
  const root = {isConnected:true,querySelectorAll:()=>[{dataset:{nodeId:'lower-row'},querySelector:()=>target}]};
  focusAcceptedSection(root,{body:{},activeElement:null},'lower-row',{schedule:callback=>frames.push(callback)});
  frames.shift()();
  assert.equal(tree.scrollTop,66);
  assert.equal(panel.scrollTop,0);
});

test('design edits participate in autosave while viewport and unreviewed theme remain distinct', () => {
  const state = editor({});
  state.$wire = { formData: { heading: 'Accepted' }, formBaseline: { heading: 'Accepted' }, designData: { align: null }, designBaseline: { align: null }, designThemeChoice: null, designThemeBaseline: null };
  state.queue = { blocked: false };
  state.changed(false);
  assert.equal(state.dirty, false);
  state.viewport = 'mobile';
  state.changed(false);
  assert.equal(state.dirty, false, 'a viewing width does not become a persisted override');
  state.$wire.designData.align = 'center';
  state.changed(false);
  assert.equal(state.dirty, true);
  state.$wire.designBaseline.align = 'center';
  state.changed(false);
  assert.equal(state.dirty, false);
  state.$wire.designThemeChoice = 'standard.base@1:editorial';
  assert.equal(state.pendingThemeChoice(), true);
  assert.equal(state.dirty, false, 'a theme candidate requires reviewed application rather than autosave');
});

test('an accepted reset focuses the persistent disclosure rather than disappearing reset or recovery controls', async () => {
  const oldDocument = globalThis.document, oldRaf = globalThis.requestAnimationFrame;
  const frames = [];
  let focused = '';
  const reset = { isConnected: false };
  const summary = { isConnected: true, disabled: false, getClientRects: () => [1], focus: () => { focused = 'inheritance'; } };
  globalThis.document = { body: {}, activeElement: reset };
  globalThis.requestAnimationFrame = callback => frames.push(callback);
  const state = editor({});
  state.$wire = { formData: {}, formBaseline: {}, designData: {}, designBaseline: {}, readOnly: false, previewUrl: '', message: 'Override reset to inheritance.' };
  state.$refs = {};
  state.editorElement = { isConnected: true, contains: () => false, querySelector: selector => selector === '[data-design-reset-summary]' ? summary : null };
  state.$nextTick = callback => callback();
  state.queue = new WriteQueue(async (_action, _key, requestId) => ({ ok: true, requestId, version: 1 }));
  await state.run('resetDesignKey', 'align');
  frames.shift()();
  assert.equal(focused, 'inheritance');
  assert.equal(state.announcement, '', 'the concise server status is not announced twice');
  globalThis.document = oldDocument;
  globalThis.requestAnimationFrame = oldRaf;
});

test('tree keyboard movement selects an inactive row first and never edits through readonly or blocked controls', () => {
  const state=editor({});
  const calls=[];
  state.$wire={selectedId:'first',readOnly:false};
  state.queue={blocked:false};
  state.run=(...args)=>calls.push(args);
  state.treeAction('second','moveSection',-1);
  assert.deepEqual(calls,[['selectNode','second']], 'an inactive row only changes selection');
  state.$wire.selectedId='second';
  state.treeAction('second','moveSection',-1);
  assert.deepEqual(calls.at(-1),['moveSection','second',-1]);
  const count=calls.length;
  state.queue.blocked=true;
  state.treeAction('second','moveSectionIn');
  state.queue.blocked=false; state.$wire.readOnly=true;
  state.treeAction('second','moveSectionOut');
  assert.equal(calls.length,count);
});
