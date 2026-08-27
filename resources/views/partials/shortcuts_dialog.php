<?php /* Globale Tastaturkürzel-Übersicht (NFR-UI-12), erreichbar über "?". */ ?>
<div
    x-data="shortcutsOverview"
    x-show="open"
    x-cloak
    role="dialog"
    aria-modal="true"
    aria-labelledby="shortcuts-dialog-title"
    class="fixed inset-0 z-[140] flex items-center justify-center p-5"
    style="background-color: rgb(0 0 0 / 0.45);"
    @click.self="close"
    @keydown.escape.window="close"
>
    <div class="w-full max-w-md rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4">
            <h2 id="shortcuts-dialog-title" class="text-xl font-semibold">Tastaturkürzel</h2>
            <button type="button" @click="close" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>
        <dl class="mt-5 space-y-3 text-sm">
            <div class="flex items-center justify-between gap-4"><dt>Suche öffnen</dt><dd class="shortcut-key">Strg/Cmd + K</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Notiz speichern</dt><dd class="shortcut-key">Strg/Cmd + S</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Fett</dt><dd class="shortcut-key">Strg/Cmd + B</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Kursiv</dt><dd class="shortcut-key">Strg/Cmd + I</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Rückgängig</dt><dd class="shortcut-key">Strg/Cmd + Z</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Wiederholen</dt><dd class="shortcut-key">Strg/Cmd + Umschalt + Z</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Dialog schließen</dt><dd class="shortcut-key">Esc</dd></div>
            <div class="flex items-center justify-between gap-4"><dt>Diese Übersicht</dt><dd class="shortcut-key">?</dd></div>
        </dl>
    </div>
</div>
