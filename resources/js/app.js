import { saveBar, settingsBatch, wireSource } from './save-bar';
import { quickFind, revealSetting } from './quick-find';

// Alpine comes with Livewire; register components before it starts.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('saveBar', saveBar);
    window.Alpine.data('settingsBatch', settingsBatch);
    window.Alpine.data('wireSource', wireSource);
    window.Alpine.data('quickFind', quickFind);
});

// A link to one setting (from quick find) opens at that setting once the page has drawn.
window.addEventListener('load', () => setTimeout(revealSetting, 50));

// Back and forward show the copy of the page Livewire kept from when you left
// it, so a list looked the same until a reload. After such a restore, every
// component on the page fetches itself again (one request for all of them).
let restored = false;
document.addEventListener('livewire:navigate', (e) => { restored = Boolean(e.detail?.history && e.detail?.cached); });
document.addEventListener('livewire:navigated', () => {
    if (!restored) return;
    restored = false;
    window.Livewire?.all().forEach((component) => component.$wire.$refresh());
});
