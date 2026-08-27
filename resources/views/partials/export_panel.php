<?php /* Export ausgewählter Notizbücher als ZIP (FR-EXP-03). Eigene
         Alpine-Komponente, damit die Auswahl nicht im Zustand der
         Offline-Einstellungen hängt - genauso wie beim Import daneben. */ ?>
<div x-data="noteExport" class="mt-4 rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
    <div class="flex items-center gap-3">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl" style="background: var(--color-bg); color: var(--color-text-muted);" x-icon="folder"></span>
        <div class="min-w-0 flex-1">
            <p class="font-medium" style="color: var(--color-text);">Notizbücher exportieren</p>
            <p class="text-xs" style="color: var(--color-text-muted);">ZIP mit Markdown-Dateien, Bildern und Anhängen.</p>
        </div>
    </div>
    <button type="button" class="btn btn-secondary mt-3 w-full" @click="openDialog">Export starten</button>

    <?php /* Ins <body> teleportiert und über dem Einstellungen-Dialog: Eltern mit
             Transform würden die zentrierte Position kippen. */ ?>
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-labelledby="export-dialog-title"
            class="fixed inset-0 z-[100] flex items-center justify-center p-5"
            style="background-color: rgb(0 0 0 / 0.4);"
            @click.self="closeDialog"
            @keydown.escape.window="closeDialog"
        >
            <div class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="export-dialog-title" class="text-xl font-semibold">Notizbücher exportieren</h2>
                        <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                            Jede Seite wird zu einer Markdown-Datei mit Frontmatter; Bilder und
                            Anhänge landen je Notizbuch im Unterordner <span class="font-medium" style="color: var(--color-text);">files/</span>.
                        </p>
                    </div>
                    <button type="button" @click="closeDialog" class="icon-action shrink-0" aria-label="Dialog schließen" x-icon="x"></button>
                </div>

                <p x-show="error" x-cloak x-text="error" class="mt-4 rounded-md p-3 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>

                <p x-show="loading" class="mt-6 text-sm" style="color: var(--color-text-muted);">Notizbücher werden geladen…</p>

                <div x-show="!loading && notebooks.length === 0" x-cloak class="mt-6 text-sm" style="color: var(--color-text-muted);">
                    Es gibt noch keine Seiten zum Exportieren.
                </div>

                <div x-show="!loading && notebooks.length > 0" x-cloak class="mt-4 flex min-h-0 flex-1 flex-col">
                    <div class="flex items-center gap-3 text-sm">
                        <button type="button" class="font-medium underline" style="color: var(--color-accent);" @click="selectAll">Alle</button>
                        <button type="button" class="font-medium underline" style="color: var(--color-accent);" @click="selectNone">Keine</button>
                        <span class="ml-auto" style="color: var(--color-text-muted);" x-text="summaryLabel()"></span>
                    </div>

                    <div class="mt-3 min-h-0 flex-1 overflow-y-auto rounded-md border" style="border-color: var(--color-border);">
                        <template x-for="notebook in notebooks" :key="notebook.key">
                            <label class="flex cursor-pointer items-center gap-3 border-b px-3 py-2.5 last:border-b-0" style="border-color: var(--color-border);">
                                <input
                                    type="checkbox"
                                    class="size-4 shrink-0"
                                    style="accent-color: var(--color-accent);"
                                    :checked="isSelected(notebook)"
                                    :disabled="notebook.page_count === 0"
                                    @change="toggle(notebook)"
                                >
                                <span class="min-w-0 flex-1 truncate text-sm" x-text="notebook.name"></span>
                                <span class="shrink-0 text-xs" style="color: var(--color-text-muted);" x-text="countLabel(notebook)"></span>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" class="btn btn-quiet" @click="closeDialog">Abbrechen</button>
                    <button type="button" class="btn btn-primary" :disabled="!canExport()" @click="startExport" x-text="exportLabel()"></button>
                </div>
            </div>
        </div>
    </template>
</div>
