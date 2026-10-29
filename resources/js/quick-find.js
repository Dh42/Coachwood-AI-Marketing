// Quick find (resources/views/components/quick-find.blade.php) and the
// jump to a setting it links to (#setting-row-…).

export function quickFind() {
    return {
        open: false,
        query: '',
        active: 0,
        entries: [],
        mac: /Mac|iPhone|iPad/.test(navigator.platform),
        init() {
            try {
                this.entries = JSON.parse(this.$root.querySelector('[data-quick-find-entries]').textContent);
            } catch {
                this.entries = [];
            }
        },
        get results() {
            const words = this.query.toLowerCase().split(/\s+/).filter(Boolean);
            if (!words.length) return this.entries.slice(0, 12);
            const scored = this.entries
                .map((e) => {
                    const title = e.title.toLowerCase();
                    const text = `${title} ${e.where.toLowerCase()}`;
                    if (!words.every((w) => text.includes(w))) return null;
                    return { e, score: (title.startsWith(words[0]) ? 0 : title.includes(words[0]) ? 1 : 2) };
                })
                .filter(Boolean);
            return scored.sort((a, b) => a.score - b.score).slice(0, 30).map((s) => s.e);
        },
        shortcut(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                this.open ? (this.open = false) : this.show();
            }
        },
        show() {
            this.open = true;
            this.query = '';
            this.active = 0;
            this.$nextTick(() => this.$refs.input.focus());
        },
        move(step) {
            const count = this.results.length;
            if (count) this.active = (this.active + step + count) % count;
        },
        go(entry) {
            if (!entry) return;
            this.open = false;
            const url = new URL(entry.url, window.location.href);
            // Same page: just jump to the setting.
            if (url.pathname === window.location.pathname && url.hash) {
                window.location.hash = url.hash;
                revealSetting();
            } else {
                window.location.href = entry.url;
            }
        },
    };
}

/** Scrolls to the setting named in the address, opening "Advanced" if it's folded there, and marks it for a moment. */
export function revealSetting() {
    const id = decodeURIComponent(window.location.hash.slice(1));
    if (!id.startsWith('setting-row-')) return;
    const row = document.getElementById(id);
    if (!row) return;
    row.closest('details')?.setAttribute('open', '');
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('ring-2', 'ring-amber-400', 'ring-inset');
    setTimeout(() => row.classList.remove('ring-2', 'ring-amber-400', 'ring-inset'), 2500);
}
