<?php /* Import eines Markdown-Archivs aus einem anderen Notizwerkzeug (FR-IMP-19).
         Sitzt im Einstellungen-Dialog; eigene Alpine-Komponente, damit der
         Import-Dialog den Zustand der Offline-Einstellungen nicht mitschleppt. */ ?>
<div x-data="noteImport" class="mt-4 rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
    <div class="flex items-center gap-3">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl" style="background: var(--color-bg); color: var(--color-text-muted);" x-icon="upload"></span>
        <div class="min-w-0 flex-1">
            <p class="font-medium" style="color: var(--color-text);">Notizen importieren</p>
            <p class="text-xs">Markdown-ZIP oder verschlüsselte Notizdatei.</p>
        </div>
    </div>
    <button type="button" class="btn btn-secondary mt-3 w-full" @click="openDialog">Archiv importieren</button>

    <?php /* Ins <body> teleportiert und über dem Einstellungen-Dialog: Eltern mit
             Transform würden die zentrierte Position kippen. */ ?>
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-archive-dialog-title"
            class="fixed inset-0 z-[100] flex items-center justify-center p-5"
            style="background-color: rgb(0 0 0 / 0.4);"
            @click.self="closeDialog"
            @keydown.escape.window="closeDialog"
        >
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="import-archive-dialog-title" class="text-xl font-semibold">Notizen importieren</h2>
                        <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                            ZIP-Archiv mit Markdown-Dateien oder eine aus einem Workspace-Export extrahierte <code>.encrypted-note.json</code>-Datei.
                        </p>
                    </div>
                    <button type="button" @click="closeDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
                </div>

                <div x-show="!report" x-cloak>
                    <ul class="mt-4 space-y-1 text-sm" style="color: var(--color-text-muted);">
                        <li>Jede <code>.md</code>-Datei wird zu einer Notizseite; der Dateiname wird zum Titel.</li>
                        <li>Eingebettete Bilder werden übernommen, sonstige Dateien werden Anhänge der Seite.</li>
                        <li>Erstell- und Änderungsdatum aus dem Archiv bleiben erhalten.</li>
                        <li>Verschlüsselte Notizen werden ausschließlich im Browser entschlüsselt und für die neue Seiten-ID wieder verschlüsselt.</li>
                        <li x-show="limitHint()" x-cloak x-text="limitHint()"></li>
                    </ul>

                    <input x-ref="archiveInput" type="file" accept=".zip,application/zip,.encrypted-note.json,application/json" class="hidden" @change="chooseFile">
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <button type="button" class="btn btn-secondary" :disabled="busy" @click="pickFile">
                            <span x-icon="upload"></span>Archiv wählen
                        </button>
                        <span class="min-w-0 flex-1 truncate text-sm" style="color: var(--color-text-muted);" x-text="fileLabel()"></span>
                    </div>

                    <div x-show="fileKind === 'encrypted'" x-cloak class="mt-5">
                        <label for="encrypted-note-import-password" class="block text-sm font-medium">Kennwort der Notiz</label>
                        <input id="encrypted-note-import-password" x-model="encryptionPassword" type="password" autocomplete="current-password" maxlength="1024" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
                        <p class="mt-2 text-xs" style="color: var(--color-text-muted);">Das Kennwort und der entschlüsselte Inhalt verlassen diesen Browser nicht.</p>
                    </div>

                    <div x-show="busy" x-cloak class="mt-5">
                        <div class="h-2 w-full overflow-hidden rounded-full" style="background: var(--color-bg-subtle);">
                            <div class="h-full transition-all" style="background: var(--color-accent);" :style="'width: ' + progress + '%'"></div>
                        </div>
                        <p class="mt-2 text-sm" style="color: var(--color-text-muted);" x-text="progressLabel()"></p>
                        <?php /* Nur während der Übertragung: Ein laufender Import lässt
                                 sich nicht mehr sinnvoll zurücknehmen. */ ?>
                        <button x-show="!processing" type="button" class="mt-2 text-sm underline" style="color: var(--color-text-muted);" @click="cancelImport">Übertragung abbrechen</button>
                    </div>
                </div>

                <?php /* Ergebnisbericht: Was angelegt wurde und was nicht mitkam. */ ?>
                <div x-show="report" x-cloak class="mt-5">
                    <p class="font-medium" style="color: var(--color-success);" x-text="summaryLine()"></p>

                    <div x-show="hasNotes()" x-cloak class="mt-3">
                        <ul class="space-y-1 text-sm" style="color: var(--color-text-muted);">
                            <template x-for="line in noteLines()" :key="line">
                                <li x-text="line"></li>
                            </template>
                        </ul>
                        <button
                            x-show="detailRows().length > 0"
                            type="button"
                            class="mt-2 text-sm underline"
                            style="color: var(--color-text-muted);"
                            @click="toggleDetails"
                            x-text="detailsOpen ? 'Einzelheiten ausblenden' : 'Einzelheiten anzeigen'"
                        ></button>
                        <div x-show="detailsOpen" x-cloak class="mt-2 max-h-48 overflow-y-auto rounded-lg border p-3 text-xs" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <template x-for="row in detailRows()" :key="row.kind + row.name + row.reason">
                                <p class="mb-1">
                                    <span class="font-medium" x-text="row.kind"></span>
                                    <span x-text="': ' + row.name + ' — ' + row.reason"></span>
                                </p>
                            </template>
                        </div>
                    </div>
                </div>

                <p x-show="error" x-cloak x-text="error" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>

                <div class="mt-6 flex flex-col gap-2">
                    <button x-show="!report" type="button" class="btn btn-primary w-full" :disabled="busy || !fileName || tooLarge || (fileKind === 'encrypted' && !encryptionPassword)" @click="startImport">Import starten</button>
                    <button type="button" class="btn btn-quiet w-full" :disabled="busy" @click="closeDialog" x-text="report ? 'Fertig' : 'Abbrechen'"></button>
                </div>
            </div>
        </div>
    </template>
</div>
