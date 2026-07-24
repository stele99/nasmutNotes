<?= $vite->tags('editor/index.js', $cspNonce ?? null) ?>
<div class="page-canvas mx-auto px-6 pb-16 pt-20 sm:px-10 md:pt-14" x-data="noteEditorPage">
    <div class="page-toolbar flex items-center gap-2">
        <span style="color: var(--color-text-muted);" x-icon="file-text"></span>
        Notiz
        <span style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
        <span class="truncate" x-text="pageTitle"></span>
    </div>
    <div class="pt-10">
        <div class="mb-10 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text truncate text-4xl font-semibold tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
                <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="w-full min-w-0 rounded-md border px-3 py-2 text-3xl font-semibold tracking-tight sm:text-4xl" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="shrink-0 text-right text-sm" style="color: var(--color-text-muted);">
                <p x-text="statusLabel()"></p>
                <p class="mt-1 max-w-64" x-text="lastEditedLabel()"></p>
            </div>
        </div>

        <div
            x-show="status === 'conflict'"
            class="mb-8 flex flex-col gap-3 rounded-lg p-4 text-base sm:flex-row sm:items-center sm:justify-between"
            style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);"
        >
            <span>Diese Seite wurde zwischenzeitlich anderswo geändert.</span>
            <button @click="keepMyVersion()" class="underline text-sm font-medium">Trotzdem meine Version speichern</button>
        </div>

        <div class="editor-toolbar mb-5 flex flex-wrap items-center gap-1 border-b pb-4" style="border-color: var(--color-border);" x-ref="toolbar">
            <button type="button" data-editor-command="bold" class="toolbar-button" title="Fett" aria-label="Fett" x-icon="bold"></button>
            <button type="button" data-editor-command="italic" class="toolbar-button" title="Kursiv" aria-label="Kursiv" x-icon="italic"></button>
            <button type="button" data-editor-command="strike" class="toolbar-button toolbar-text" title="Durchgestrichen" aria-label="Durchgestrichen">S</button>
            <span class="toolbar-divider"></span>
            <button type="button" data-editor-command="heading1" class="toolbar-button" title="Überschrift 1" aria-label="Überschrift 1" x-icon="heading-1"></button>
            <button type="button" data-editor-command="heading2" class="toolbar-button" title="Überschrift 2" aria-label="Überschrift 2" x-icon="heading-2"></button>
            <button type="button" data-editor-command="bulletList" class="toolbar-button" title="Aufzählung" aria-label="Aufzählung" x-icon="list"></button>
            <button type="button" data-editor-command="blockquote" class="toolbar-button" title="Zitat" aria-label="Zitat" x-icon="quote"></button>
            <button type="button" data-editor-command="link" class="toolbar-button" title="Link" aria-label="Link" x-icon="link"></button>
            <span class="toolbar-divider"></span>
            <button type="button" data-editor-command="undo" class="toolbar-button" title="Rückgängig" aria-label="Rückgängig" x-icon="undo"></button>
            <button type="button" data-editor-command="redo" class="toolbar-button" title="Wiederholen" aria-label="Wiederholen" x-icon="redo"></button>
        </div>
        <div class="prose-editor" x-ref="editor"></div>
    </div>
</div>
