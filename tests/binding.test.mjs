import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import { validMessage, previewMessage } from '#site-builder-core-protocol';

const frame = {};
const context = { origin: 'https://demo.test', source: frame, pageId: 7, locale: 'en', session: 'tab', channel: 'current-frame' };

test('binding selection/highlight accepts only exact non-authorizing scope and fixed region enums', () => {
  for (const type of ['select-binding', 'highlight-binding']) for (const binding of ['header','footer','siteDesign']) {
    const data = previewMessage(context,type,{binding});
    const event = {origin:context.origin,source:frame,data};
    assert.equal(validMessage(event,context,[type]),true);
    for (const patch of [{binding:'body'},{binding:'arbitrary-node'},{binding:{script:'anything'}},{channel:'old-frame'},{session:'another-tab'},{pageId:8},{locale:'de'},{nodeId:'fake-node'},{type:'publish'}]) assert.equal(validMessage({...event,data:{...data,...patch}},context,[type]),false);
    assert.equal(validMessage({...event,origin:'https://evil.test'},context,[type]),false);
    assert.equal(validMessage({...event,source:{}},context,[type]),false);
  }
  assert.equal(validMessage({origin:context.origin,source:frame,data:previewMessage(context,'select-binding',{binding:null})},context,['select-binding']),false);
});

test('distributed preview selects a mounted region without source-node IDs and retains navigation suppression', async () => {
  const listeners = {}, posted = [];
  const binding = {dataset:{siteBuilderBinding:'header'},style:{}};
  const mounted = {dataset:{siteBuilderNode:'untrusted-source-node'},style:{}};
  class Element { closest(selector) { if(selector==='[data-site-builder-binding]') return binding; if(selector==='[data-site-builder-node]') return mounted; return this; } }
  const parent = {postMessage:(message,origin)=>posted.push({message,origin})};
  const window = {parent,location:{origin:context.origin},addEventListener:(type,handler)=>{listeners[type]=handler;}};
  const document = {addEventListener:(type,handler)=>{listeners[type]=handler;},getElementById:()=>({textContent:JSON.stringify(context)}),querySelectorAll:selector=>selector==='[data-site-builder-node]'?[mounted]:[binding]};
  runInNewContext(await readFile(new URL('../vendor/gregsphotography/site-builder-core/resources/dist/preview.js',import.meta.url),'utf8'),{window,document,Element});
  let prevented=0,stopped=0;
  listeners.click({target:new Element(),preventDefault:()=>prevented++,stopImmediatePropagation:()=>stopped++});
  assert.equal(posted.at(-1).message.type,'select-binding');
  assert.equal(posted.at(-1).message.binding,'header');
  assert.equal('nodeId' in posted.at(-1).message,false);
  assert.equal(prevented,1); assert.equal(stopped,1);
  const data=previewMessage(context,'highlight-binding',{binding:'header'});
  listeners.message({origin:context.origin,source:{},data});
  assert.equal(binding.style.outline,undefined);
  listeners.message({origin:context.origin,source:parent,data});
  assert.match(binding.style.outline,/2px/);
  assert.equal(mounted.style.outline,'');
});

test('site-part recovery excludes independent staging/history capabilities and keeps authored input', async () => {
  const { default: factory } = await import('../resources/js/site-part-editor.js');
  const oldDocument=globalThis.document,oldURL=globalThis.URL;
  let artifact;
  globalThis.URL={createObjectURL:blob=>{artifact=blob;return 'blob:resource';},revokeObjectURL:()=>{}};
  globalThis.document={createElement:()=>({click:()=>{}})};
  try {
    const state=factory({partId:'resource-id',kind:'design',csrf:'csrf-secret'});
    state.$wire={kind:'design',locale:'en',writeVersion:9,payloadJson:'{"authored":"retained"}',formData:{heading:'Pending'},settingsData:{name:'Pending name'},designData:{width:'narrow'},leaseToken:'resource-lease-secret',stageLeases:{7:'page-lease-secret'},draftHistoryContextData:{id:'journal-secret',epoch:'epoch-secret'}};
    state.download();
    const text=await artifact.text();
    for(const secret of ['csrf-secret','resource-lease-secret','page-lease-secret','journal-secret','epoch-secret']) assert.equal(text.includes(secret),false);
    assert.equal(JSON.parse(text).form.heading,'Pending');
    assert.equal(state.panel,'design');
  } finally {globalThis.document=oldDocument;globalThis.URL=oldURL;}
});
