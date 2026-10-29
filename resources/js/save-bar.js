// One save bar per page (<x-save-bar />) for forms that save together.
// Each form on the page is a "source": it tells the bar how many unsaved
// changes it has, and saves or discards them when the bar asks. Events:
//   save-bar-dirty   {id, count}  a source's unsaved changes (sent on every change)
//   save-bar-request              a form asks the bar to save (pressing Enter)
//   save-bar-flush                put typed-but-not-added text into the form first
//   save-bar-save / save-bar-discard   the bar asks every source
//   save-bar-done    {id, ok}     a source finished saving

const send = (name, detail = {}) => window.dispatchEvent(new CustomEvent(name, { detail }));

/**
 * Window listeners that go when the element does. Pages change without a
 * reload (wire:navigate), so a bar or form left listening would keep
 * answering for a page that's gone: stale counts after a save, a save that
 * never finishes, and a "leave this page?" prompt with nothing unsaved.
 */
function listeners() {
    const bound = [];
    return {
        on(name, handler) { bound.push([name, handler]); window.addEventListener(name, handler); },
        off() { bound.splice(0).forEach(([name, handler]) => window.removeEventListener(name, handler)); },
    };
}

/** A value as text for comparing: '10' and 10, null and '' are the same. */
export function same(value) {
    if (value === null || value === undefined) return '';
    if (Array.isArray(value)) return JSON.stringify(value.map(same));
    if (typeof value === 'object') return JSON.stringify(Object.keys(value).sort().map((k) => [k, same(value[k])]));
    return String(value);
}

export function saveBar() {
    return {
        counts: {},
        pending: null,
        failed: false,
        get total() {
            return Object.values(this.counts).reduce((sum, n) => sum + n, 0);
        },
        get saving() {
            return this.pending !== null;
        },
        listeners: listeners(),
        init() {
            this.listeners.on('save-bar-dirty', (e) => { this.counts = { ...this.counts, [e.detail.id]: e.detail.count }; });
            this.listeners.on('save-bar-request', () => this.save());
            this.listeners.on('save-bar-done', (e) => this.done(e.detail));
            this.listeners.on('beforeunload', (e) => {
                if (this.total > 0) e.preventDefault();
            });
        },
        destroy() {
            this.listeners.off();
        },
        save() {
            if (this.saving) return;
            send('save-bar-flush');
            this.failed = false;
            // Only the forms with changes are asked, so a form that can't answer doesn't hold the bar on "Saving…".
            this.pending = new Set(Object.keys(this.counts).filter((id) => this.counts[id] > 0));
            if (!this.pending.size) this.pending = null;
            send('save-bar-save');
        },
        done({ id, ok }) {
            if (!this.pending) return;
            this.failed ||= !ok;
            this.pending.delete(id);
            if (this.pending.size) return;
            this.pending = null;
            if (this.failed) {
                requestAnimationFrame(() => {
                    const error = document.querySelector('[data-save-error]');
                    error?.closest('details')?.setAttribute('open', '');
                    error?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            }
        },
        discard() {
            send('save-bar-discard');
        },
    };
}

/**
 * A source comparing a Livewire component's fields with their saved copy.
 * fields() and saved() return objects; save() resolves to true when saved.
 */
function source(id, { fields, saved, save, discard }) {
    return {
        dirty: [],
        drafts: {},
        listeners: listeners(),
        init() {
            this.$watch(() => same(fields.call(this)) + same(saved.call(this)), () => this.check());
            this.$el.addEventListener('settings-draft', (e) => { this.drafts = { ...this.drafts, [e.detail.field]: e.detail.typed }; this.check(); });
            this.listeners.on('save-bar-save', () => this.save());
            this.listeners.on('save-bar-discard', () => this.discard());
            this.check();
        },
        destroy() {
            this.listeners.off();
            send('save-bar-dirty', { id, count: 0 });
        },
        check() {
            const now = fields.call(this) || {};
            const was = saved.call(this) || {};
            this.dirty = Object.keys(now).filter((f) => same(now[f]) !== same(was[f]) || this.drafts[f]);
            send('save-bar-dirty', { id, count: this.dirty.length });
        },
        async save() {
            this.check();
            if (!this.dirty.length) return send('save-bar-done', { id, ok: true });
            let ok = false;
            try {
                ok = (await save.call(this, this.dirty)) === true;
            } catch (e) {
                console.error('Saving failed', e); // The bar shows it failed instead of staying on "Saving…".
            }
            this.drafts = {};
            this.$nextTick(() => this.check());
            send('save-bar-done', { id, ok });
        },
        async discard() {
            if (!this.dirty.length && !Object.values(this.drafts).some(Boolean)) return;
            this.drafts = {};
            await discard.call(this);
            this.$nextTick(() => this.check());
        },
    };
}

/** A settings form (SettingsForm with batch on). */
export function settingsBatch(id) {
    return source(id, {
        fields() { return this.$wire.values; },
        saved() { return this.$wire.saved; },
        save(dirty) { return this.$wire.saveAll(dirty); },
        discard() { return this.$wire.discard(); },
    });
}

/**
 * Some of a component's own fields, saved by one of its methods. The saved
 * copy is an object by field name, or, for a single field, its plain value.
 */
export function wireSource(id, names, savedProperty, saveMethod, discardMethod) {
    const pick = (obj) => Object.fromEntries(names.map((n) => [n, obj?.[n]]));
    const savedCopy = (value) => (names.length === 1 && (value === null || typeof value !== 'object') ? { [names[0]]: value } : pick(value));
    return source(id, {
        fields() { return pick(this.$wire); },
        saved() { return savedCopy(this.$wire[savedProperty]); },
        save() { return this.$wire[saveMethod](); },
        discard() { return this.$wire[discardMethod](); },
    });
}
