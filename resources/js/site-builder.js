import { WriteQueue, stableState } from './write-queue.js';
import { copyStructuredText, readStructuredText, handlesDocumentHistory, newTypingGroup } from './clipboard.js';
import { captureEditorFocus, focusAcceptedSection, focusEditorControl, resolveEditorRoot } from './focus.js';
import { validMessage, previewMessage } from '#site-builder-core-protocol';

export default function siteBuilderEditor(config) {
  return {
    panel: 'sections', viewport: 'desktop', busy: false, saving: false, dirty: false, error: '', timer: null, confirmReload: false,
    pickerSearch: '', pickerCategory: '', pickerKind: 'component', pickerDismissed: false, pickerOpener: null, draggedNode: null, clipboardOpener: null, clipboardDismissed: false, clipboardMessage: '', clipboardCheckedText: '', showCapture: false, announcement: '', focusEpoch: 0, editorElement: null, typingGroup: newTypingGroup(), typingBoundary: false, historyState: { available: false, canUndo: false, canRedo: false, undoCount: 0, redoCount: 0 },
    init() {
      this.editorElement = this.$root || this.$el;
      this.historyState = { ...(this.$wire.draftHistoryState || this.historyState) };
      this.queue = new WriteQueue((action, ...args) => this.$wire.$call(action, ...args), this.$wire.writeVersion);
      this.stopInterceptor = this.$wire.$interceptMessage(({ onFailure, onError, onSuccess }) => {
        const failed = () => {
          this.queue.blocked = true;
          this.error = 'The request could not be confirmed. Keep or download your input, then reload saved content.';
        };
        onFailure(failed);
        onError(failed);
        onSuccess(({ onMorphed }) => onMorphed(() => this.changed(this.$wire.state !== 'invalid')));
      });
      this.$watch('$wire.formData', () => this.changed());
      this.$watch('$wire.formBaseline', () => this.changed(false));
      this.$watch('$wire.designData', () => this.changed());
      this.$watch('$wire.designBaseline', () => this.changed(false));
      this.messageListener = event => {
        const frame = this.$refs.preview;
        if (!frame || !this.$wire.previewUrl) return;
        const expected = { origin: new URL(this.$wire.previewUrl, location.href).origin, source: frame.contentWindow, pageId: config.pageId, locale: this.$wire.locale, session: config.session, channel: this.$wire.channel };
        if (!validMessage(event, expected, ['ready', 'select', 'select-binding'])) return;
        if (event.data.type === 'ready') this.highlight();
        else if (event.data.type === 'select-binding') this.run('selectSitePartBinding', event.data.binding);
        else this.run('selectNode', event.data.nodeId);
      };
      window.addEventListener('message', this.messageListener);
      this.historyKeyListener = event => {
        const action = handlesDocumentHistory(event, { blocked: this.queue.blocked || this.$wire.readOnly, busy: this.busy, modalOpen: (this.$wire.pickerOpen && !this.pickerDismissed) || (this.$wire.clipboardOpen && !this.clipboardDismissed) });
        if (!action || !this.historyState?.[action === 'undoDraft' ? 'canUndo' : 'canRedo']) return;
        event.preventDefault(); this.run(action);
      };
      window.addEventListener('keydown', this.historyKeyListener);
      this.leaveListener = () => {
        const body = new FormData(); body.set('_token', config.csrf); body.set('token', this.$wire.leaseToken);
        navigator.sendBeacon(config.releaseUrl, body);
      };
      this.unloadListener = event => {
        if (this.dirty || this.clipboardInputPending() || this.pendingThemeChoice() || stableState(this.$wire.settingsData) !== stableState(this.$wire.settingsBaseline) || this.queue.blocked) { event.preventDefault(); event.returnValue = ''; }
      };
      this.resumeListener = event => { if (event.persisted) { this.queue.blocked = true; this.error = 'Reload saved content to request a fresh editing lease.'; } };
      window.addEventListener('pagehide', this.leaveListener);
      window.addEventListener('beforeunload', this.unloadListener);
      window.addEventListener('pageshow', this.resumeListener);
      this.heartbeatTimer = setInterval(() => {
        if (!this.$wire.readOnly && !this.queue.blocked && !this.busy) this.run('heartbeat');
      }, config.heartbeat * 1000);
    },
    changed(schedule = true) {
      this.dirty = stableState(this.$wire.formData) !== stableState(this.$wire.formBaseline) || stableState(this.$wire.designData) !== stableState(this.$wire.designBaseline);
      clearTimeout(this.timer);
      if (this.dirty && schedule && !this.$wire.readOnly && !this.queue.blocked) {
        if (this.typingBoundary) { this.typingGroup = newTypingGroup(); this.typingBoundary = false; }
        const group = this.typingGroup;
        this.timer = setTimeout(() => this.run('autosaveSelected', group), 650);
      }
    },
    pendingThemeChoice() {
      return (this.$wire.designThemeChoice || null) !== (this.$wire.designThemeBaseline || null);
    },
    referenceChoicesReviewed() {
      return stableState(this.$wire.pickerReferenceValues || {}) === stableState(this.$wire.pickerReferenceBaseline || {});
    },
    clipboardInputPending() {
      return !!this.$wire.clipboardText;
    },
    clipboardChoicesReviewed() {
      return !!this.$wire.clipboardReview?.textDigest && this.$wire.clipboardText === this.clipboardCheckedText && stableState(this.$wire.clipboardReview.position || null) === stableState({ parentId: this.$wire.clipboardParentId || null, kind: this.$wire.clipboardPlacement, anchor: this.$wire.clipboardAnchor || null }) && stableState(this.$wire.clipboardReferenceValues || {}) === stableState(this.$wire.clipboardReferenceBaseline || {});
    },
    openClipboard(mode = 'paste', position = 'end', anchor = null, parentId = null) {
      this.clipboardOpener = document.activeElement;
      this.clipboardDismissed = false;
      this.clipboardMessage = '';
      this.run('openTreeClipboard', mode, parentId, position, anchor);
    },
    dismissClipboard() {
      if (this.busy) return;
      if (this.queue.blocked) {
        this.clipboardDismissed = true;
        this.clipboardOpener?.focus({ preventScroll: true });
        return;
      }
      this.run('closeClipboard');
    },
    trapClipboard(event) {
      if (!this.$wire.clipboardOpen || this.clipboardDismissed) return;
      const controls = [...this.$refs.clipboardDialog.querySelectorAll('button,input,select,textarea,a[href],[tabindex="0"]')].filter(element => !element.disabled && element.getClientRects().length);
      if (!controls.length) { event.preventDefault(); return; }
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && (document.activeElement === first || !this.$refs.clipboardDialog.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
    clearPastedContent() {
      this.clipboardCheckedText = '';
      if (this.queue.blocked) {
        this.$wire.clipboardText = '';
        this.clipboardMessage = 'Pasted content cleared locally. Reload saved content to reconcile editing access.';
      } else this.run('clearClipboardInput');
    },
    async copyPreparedSection() {
      const result = await copyStructuredText(this.$wire.copiedSectionText || '');
      this.clipboardMessage = result.message;
    },
    async readClipboardText() {
      const result = await readStructuredText();
      if (result.ok) { this.$wire.clipboardText = result.text; this.clipboardCheckedText = ''; }
      this.clipboardMessage = result.message;
    },
    downloadClipboard() {
      const text = this.$wire.copiedSectionText || '';
      const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
      const link = document.createElement('a'); link.href = url; link.download = 'site-builder-section.json'; link.click(); URL.revokeObjectURL(url);
      this.clipboardMessage = 'Structured copy downloaded. Its expiry and source permissions are checked when pasting.';
    },
    treeAction(id, action, ...args) {
      if (this.$wire.selectedId !== id) { this.run('selectNode', id); return; }
      if (this.$wire.readOnly || this.queue.blocked || this.busy) return;
      this.run(action, id, ...args);
    },
    openPicker(position = 'end', anchor = null, parentId = null) {
      this.pickerOpener = document.activeElement;
      this.pickerDismissed = false;
      this.run('openTreePicker', parentId, position, anchor);
    },
    dismissPicker() {
      if (this.busy) return;
      if (this.queue.blocked) {
        this.pickerDismissed = true;
        this.pickerOpener?.focus({ preventScroll: true });
        return;
      }
      this.run('closePicker');
    },
    pickerMatches(data) {
      const search = this.pickerSearch.toLocaleLowerCase().trim();
      return data.kind === this.pickerKind && (data.kind === 'saved-section' || ((!this.pickerCategory || data.category === this.pickerCategory) && (!search || (data.search || '').includes(search))));
    },
    pickerCount() {
      return (this.$wire.pickerCatalogueState || []).filter(entry => this.pickerMatches(entry)).length;
    },
    trapPicker(event) {
      if (!this.$wire.pickerOpen || this.pickerDismissed) return;
      const controls = [...this.$refs.picker.querySelectorAll('button,input,select,textarea,a[href],iframe,[tabindex="0"]')].filter(element => !element.disabled && element.getClientRects().length);
      if (!controls.length) { event.preventDefault(); return; }
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && (document.activeElement === first || !this.$refs.picker.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
    preparePickerPreview() {
      const frame = this.$refs.pickerFrame;
      const picker = this.$refs.picker;
      try {
        const preview = frame?.contentDocument;
        if (!preview) return;
        preview.querySelectorAll('a,button,input,select,textarea,[tabindex]').forEach(element => {
          element.setAttribute('tabindex', '-1');
          if ('disabled' in element) element.disabled = true;
        });
        preview.addEventListener('keydown', event => {
          if (event.key === 'Escape') { event.preventDefault(); this.dismissPicker(); }
          else if (event.key === 'Tab') {
            event.preventDefault();
            const controls = [...picker.querySelectorAll('button,input,select,textarea,a[href],iframe,[tabindex="0"]')].filter(element => !element.disabled && element.getClientRects().length);
            const index = controls.indexOf(frame);
            controls[(index + (event.shiftKey ? -1 : 1) + controls.length) % controls.length]?.focus();
          }
        });
      } catch { /* The server preview remains read-only if a host chooses another origin. */ }
    },
    async run(action, ...args) {
      clearTimeout(this.timer);
      const autosave = action === 'autosaveSelected' || action === 'saveSelected';
      if (this.saving && autosave) return;
      if (this.busy && !autosave) return;
      const focusEpoch = ++this.focusEpoch;
      if (action === 'reloadSaved') {
        if (!this.confirmReload) {
          this.confirmReload = true;
          this.$nextTick(() => this.$refs.reloadConfirm?.focus());
          return;
        }
        this.confirmReload = false;
        this.queue.discardPending();
      }
      const browserDocument = typeof document === 'undefined' ? null : document;
      const initiatingControl = browserDocument?.activeElement;
      const editorRoot = resolveEditorRoot(this.editorElement || this.$root || this.$el, browserDocument);
      const nextTick = this.$nextTick || (callback => queueMicrotask(callback));
      const restoreFocus = editorRoot ? captureEditorFocus(editorRoot, browserDocument) : null;
      this.busy = true;
      this.saving = autosave;
      this.error = '';
      this.announcement = '';
      let response;
      let acceptedFocus;
      const submittedClipboardText = this.$wire.clipboardText;
      try {
        // Each server action also flushes the selected form. That includes programmatic callers.
        response = await this.queue.enqueue(action, args);
        if (response.history && !['conflict', 'failed'].includes(response.state)) this.historyState = { ...response.history };
        if (!response.ok) this.error = response.message || 'Check the form before continuing.';
        if (response.ok) {
          if (action !== 'autosaveSelected') { this.typingGroup = newTypingGroup(); this.typingBoundary = false; }
          if (response.announcement && !this.$wire.message) this.announcement = response.announcement;
          const acceptedNode = response.insertedIds?.[0] || response.focusNodeId;
          if (acceptedNode) acceptedFocus = () => focusAcceptedSection(editorRoot, browserDocument, acceptedNode, { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          else if (response.focusEmptyAdd) acceptedFocus = () => resolveEditorRoot(editorRoot, browserDocument)?.querySelector('[data-empty-add]')?.focus({ preventScroll: true });
          else if (['openPicker', 'openTreePicker'].includes(action)) acceptedFocus = () => resolveEditorRoot(editorRoot, browserDocument)?.querySelector(this.pickerKind === 'saved-section' ? '[data-picker-saved-search]' : '[data-picker-search]')?.focus();
          else if (action === 'closePicker') acceptedFocus = () => this.pickerOpener?.isConnected && this.pickerOpener.focus({ preventScroll: true });
          else if (action === 'reviewStartingPoint') acceptedFocus = () => resolveEditorRoot(editorRoot, browserDocument)?.querySelector('[data-picker-detail]')?.focus({ preventScroll: true });
          else if (['resetDesignKey', 'resetDesignBreakpoint', 'resetDesignScope'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, ['[data-design-reset-summary]', '[data-design-heading]'], { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          else if (action === 'prepareDesignTheme') acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-design-theme-review-heading]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          else if (['applyDesignTheme', 'discardDesignThemeChoice'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '#sb-design-theme-choice', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          if (action === 'clearClipboardInput') { this.clipboardCheckedText = ''; this.clipboardMessage = 'Pasted content cleared.'; }
          if (['openClipboard', 'openTreeClipboard'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, this.$wire.clipboardMode === 'copy' ? '[data-clipboard-copy]' : '[data-clipboard-paste]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          else if (action === 'closeClipboard') acceptedFocus = () => this.clipboardOpener?.isConnected && this.clipboardOpener.focus({ preventScroll: true });
          else if (action === 'reviewClipboard') {
            this.clipboardCheckedText = submittedClipboardText;
            acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-clipboard-review]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          }
          if (['reviewSnapshotSource', 'reviewSnapshotMappings'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-snapshot-review]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          if (['reviewSitePart', 'reviewClearSitePart'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-site-part-review]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          if (action === 'applySitePart') {
            if (acceptedNode) this.panel = 'sections';
            else acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-site-parts-heading]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          }
          if (['reviewRevisionImpact', 'applyStage', 'cancelStage'].includes(action)) acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-part-impact]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          if (action === 'prepareStage') acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-part-stage-review]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl });
          if (action === 'selectSitePartBinding') { this.panel = 'site-parts'; acceptedFocus = () => focusEditorControl(editorRoot, browserDocument, '[data-site-parts-heading]', { isCurrent: () => focusEpoch === this.focusEpoch, allowFrom: initiatingControl }); }
          if (action === 'captureSavedSection') this.showCapture = false;
        }
        this.highlight();
      } catch (error) {
        this.error = 'The save could not be confirmed. Keep or download your input, then reload saved content before continuing.';
      } finally {
        this.busy = false;
        this.saving = false;
        this.changed(false);
        if (acceptedFocus || restoreFocus) nextTick(acceptedFocus || restoreFocus);
        if (response?.ok && this.dirty && !this.$wire.readOnly && !this.queue.blocked) { const group = this.typingGroup; this.timer = setTimeout(() => this.run('autosaveSelected', group), 650); }
      }
    },
    highlight() {
      const frame = this.$refs.preview;
      if (!frame?.contentWindow || !this.$wire.previewUrl) return;
      const origin = new URL(this.$wire.previewUrl, location.href).origin;
      const context = { pageId: config.pageId, locale: this.$wire.locale, session: config.session, channel: this.$wire.channel };
      frame.contentWindow.postMessage(this.$wire.selectedSitePartBinding ? previewMessage(context, 'highlight-binding', { binding: this.$wire.selectedSitePartBinding }) : previewMessage(context, 'highlight', { nodeId: this.$wire.selectedId }), origin);
    },
    download() {
      const reviewed = this.$wire.pickerReview || {};
      const payload = { format: 'site-builder/editor-recovery', version: 1, pageId: config.pageId, locale: this.$wire.locale, selectedNodeId: this.$wire.selectedId, acceptedVersion: this.$wire.writeVersion, document: this.$wire.documentJson || this.$wire.rawDocument, form: this.$wire.formData, settings: this.$wire.settingsData,
        composition: { source: reviewed.id ? { kind: reviewed.kind, id: reviewed.id, version: reviewed.version, digest: reviewed.digest, locale: reviewed.locale, variant: reviewed.variant } : null, position: this.$wire.pickerPosition, references: this.$wire.pickerReferenceValues, capture: this.$wire.captureData, savedSection: this.$wire.savedSectionData },
        clipboard: { text: this.$wire.clipboardText, parentId: this.$wire.clipboardParentId, placement: this.$wire.clipboardPlacement, anchor: this.$wire.clipboardAnchor, references: this.$wire.clipboardReferenceValues },
        design: { scope: this.$wire.designScope, breakpoint: this.$wire.designBreakpoint, form: this.$wire.designData, themeChoice: this.$wire.designThemeChoice } };
      const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' }));
      const link = document.createElement('a'); link.href = url; link.download = `page-${config.pageId}-unsaved.json`; link.click(); URL.revokeObjectURL(url);
    },
    destroy() {
      this.stopInterceptor?.();
      clearTimeout(this.timer); clearInterval(this.heartbeatTimer);
      window.removeEventListener('message', this.messageListener);
      window.removeEventListener('keydown', this.historyKeyListener);
      window.removeEventListener('pagehide', this.leaveListener);
      window.removeEventListener('beforeunload', this.unloadListener);
      window.removeEventListener('pageshow', this.resumeListener);
    },
  };
}
