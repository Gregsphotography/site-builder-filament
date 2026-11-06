import pageEditor from './site-builder.js';

export default function sitePartEditor(config) {
  const state = pageEditor(config);
  const initialize = state.init;
  state.init = function () {
    initialize.call(this);
    window.removeEventListener('pagehide', this.leaveListener);
    this.leaveListener = () => {
      const body = new FormData(); body.set('_token', config.csrf); body.set('token', this.$wire.leaseToken);
      Object.entries(this.$wire.stageLeases || {}).slice(0, 20).forEach(([pageId, token], index) => { body.set(`targets[${index}][pageId]`, pageId); body.set(`targets[${index}][token]`, token); });
      navigator.sendBeacon(config.releaseUrl, body);
    };
    window.addEventListener('pagehide', this.leaveListener);
  };
  state.panel = config.kind === 'design' ? 'design' : 'sections';
  // Site parts have UUID owners and a read-only preview; they never impersonate page IDs.
  state.highlight = () => {};
  state.clipboardInputPending = () => false;
  state.download = function () {
    const payload = { format: 'site-builder/site-part-recovery', version: 1, resourceId: config.partId, kind: this.$wire.kind, locale: this.$wire.locale, selectedNodeId: this.$wire.selectedId, acceptedVersion: this.$wire.writeVersion, payload: this.$wire.payloadJson, form: this.$wire.formData, settings: this.$wire.settingsData, design: { scope: this.$wire.designScope, breakpoint: this.$wire.designBreakpoint, form: this.$wire.designData, themeChoice: this.$wire.designThemeChoice } };
    const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' }));
    const link = document.createElement('a'); link.href = url; link.download = `site-part-${config.partId}-unsaved.json`; link.click(); URL.revokeObjectURL(url);
  };
  return state;
}
