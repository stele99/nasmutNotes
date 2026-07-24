<div class="note-page page-canvas mx-auto px-6 pb-16 pt-20 sm:px-10 md:pt-14" x-data="noteEditorPage" data-page-id="<?= (int) $page['id'] ?>" data-page-title="<?= e((string) $page['title']) ?>" data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>">
    <div class="note-sticky-header page-toolbar flex items-center gap-2">
        <span style="color: var(--color-text-muted);" x-icon="file-text"></span>
        Notiz
        <span style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
        <span class="truncate" x-text="pageTitle"></span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <span x-show="isShared" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>
                Geteilt · <span x-text="permissionLabel()"></span>
            </span>
            <button x-show="!isShared" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border px-3 py-1.5 text-sm font-medium" style="border-color: var(--color-border);" title="Seite teilen" aria-label="Seite teilen">
                <span x-icon="share-2"></span>Teilen
            </button>
        </div>
    </div>
    <div class="pt-10">
        <div class="mb-10 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text truncate text-4xl font-semibold tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
                <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="page-title-input w-full min-w-0 text-4xl font-semibold tracking-tight sm:text-5xl">
            </div>
            <div class="flex shrink-0 items-start gap-3">
                <div class="text-right text-sm" style="color: var(--color-text-muted);">
                    <p x-text="statusLabel()"></p>
                </div>
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

        <div x-show="status === 'invalid'" x-cloak class="mb-8 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert">
            <span x-text="saveError"></span>
        </div>

        <div x-show="canEditPage" class="note-sticky-toolbar editor-toolbar mb-5 flex flex-wrap items-center gap-1 border-b pb-4" style="border-color: var(--color-border);" x-ref="toolbar">
            <button type="button" data-editor-command="bold" @click.prevent="toggleBold" class="toolbar-button" title="Fett" aria-label="Fett" x-icon="bold"></button>
            <button type="button" data-editor-command="italic" @click.prevent="toggleItalic" class="toolbar-button" title="Kursiv" aria-label="Kursiv" x-icon="italic"></button>
            <button type="button" data-editor-command="strike" @click.prevent="toggleStrike" class="toolbar-button toolbar-text" title="Durchgestrichen" aria-label="Durchgestrichen">S</button>
            <span class="toolbar-divider"></span>
            <button type="button" data-editor-command="heading1" @click.prevent="toggleHeading1" class="toolbar-button" title="Überschrift 1" aria-label="Überschrift 1" x-icon="heading-1"></button>
            <button type="button" data-editor-command="heading2" @click.prevent="toggleHeading2" class="toolbar-button" title="Überschrift 2" aria-label="Überschrift 2" x-icon="heading-2"></button>
            <button type="button" data-editor-command="bulletList" @click.prevent="toggleBulletList" class="toolbar-button" title="Aufzählung" aria-label="Aufzählung" x-icon="list"></button>
            <button type="button" data-editor-command="blockquote" @click.prevent="toggleBlockquote" class="toolbar-button" title="Zitat" aria-label="Zitat" x-icon="quote"></button>
            <button type="button" data-editor-command="link" @click.prevent="editLink" class="toolbar-button" title="Link" aria-label="Link" x-icon="link"></button>
            <span class="toolbar-divider"></span>
            <button type="button" data-editor-command="undo" @click.prevent="undo" class="toolbar-button" title="Rückgängig" aria-label="Rückgängig" x-icon="undo"></button>
            <button type="button" data-editor-command="redo" @click.prevent="redo" class="toolbar-button" title="Wiederholen" aria-label="Wiederholen" x-icon="redo"></button>
        </div>
        <p x-show="imageUploadError" x-text="imageUploadError" class="mb-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
        <div class="prose-editor" x-ref="editor"></div>
        <div x-show="linkMenuOpen" x-cloak @click.outside="closeLinkMenu" @keydown.escape.window="closeLinkMenu" class="link-action-menu" :style="linkMenuStyle">
            <button type="button" @click="openActiveLink" class="link-action-button">Öffnen</button>
            <button x-show="canEditPage" type="button" @click="editActiveLink" class="link-action-button">Bearbeiten</button>
        </div>
        <p x-show="updatedAt" class="mt-12 pb-2 text-center text-xs" style="color: var(--color-text-muted);" x-text="lastEditedLabel()"></p>
    </div>
</div>
