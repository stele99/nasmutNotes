<div class="note-page page-canvas page-content-canvas mx-auto px-4 pb-16 pt-2 sm:px-10 md:px-6 md:pt-5" x-data="noteEditorPage" data-page-id="<?= (int) $page['id'] ?>" data-page-title="<?= e((string) $page['title']) ?>" data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>" data-page-is-shared="<?= !empty($page['is_shared']) ? '1' : '0' ?>">
    <div class="note-sticky-header page-toolbar flex items-center gap-2">
        <?php /* Der Seitentitel steht mobil ohnehin direkt darunter als Überschrift -
                 der Breadcrumb kostet dort nur Höhe und entfällt deshalb. Die
                 Freigabe-Anzeige und die Aktionen bleiben sichtbar. */ ?>
        <span class="hidden min-w-0 items-center gap-2 md:flex">
            <?php $nbIcon = !empty($page['is_shared']) ? 'share-2' : ($page['notebook_icon'] ?? 'book-open'); ?>
            <?php $nbColor = !empty($page['is_shared']) ? 'var(--color-text-muted)' : ($page['notebook_color'] ?? 'var(--color-text-muted)'); ?>
            <span style="color: <?= e($nbColor) ?>;" x-icon="<?= e($nbIcon) ?>"></span>
            <span><?= e(!empty($page['is_shared']) ? 'Geteilt' : (string) ($page['notebook_name'] ?? 'Nicht zugewiesen')) ?></span>
            <span style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
            <span class="truncate" x-text="pageTitle"></span>
        </span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <span x-show="isShared" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>
                Geteilt · <span x-text="permissionLabel()"></span>
            </span>
            <span x-show="ownedAndShared()" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>Geteilt
            </span>
            <?php include __DIR__ . '/partials/page_writers.php'; ?>
            <button type="button" @click="openHistory" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium md:px-3 md:py-1.5" style="border-color: var(--color-border);" title="Versionsverlauf" aria-label="Versionsverlauf">
                <span x-icon="history"></span><span class="hidden md:inline">Verlauf</span>
            </button>
            <button x-show="!isShared && canEditPage" type="button" @click="openCompressionDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium md:px-3 md:py-1.5" style="border-color: var(--color-border);" title="Bilder komprimieren" aria-label="Bilder komprimieren">
                <span x-icon="image"></span><span class="hidden md:inline">Komprimieren</span>
            </button>
            <button x-show="!isShared && canEditPage" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium md:px-3 md:py-1.5" style="border-color: var(--color-border);" title="Seite teilen" aria-label="Seite teilen">
                <span x-icon="share-2"></span><span class="hidden md:inline">Teilen</span>
            </button>
        </div>
    </div>
    <div class="pt-4 md:pt-10">
        <div class="mb-6 flex items-start justify-between gap-4 md:mb-10">
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

        <?php /* Anhänge der Seite als Badges direkt unter der Überschrift (FR-NOTE-19).
                 Klick öffnet bzw. lädt herunter, das × entfernt den Anhang. Anhänge
                 über dem Offline-Limit sind ohne Netz als „nur online" markiert
                 (FR-OFFLINE-06). */ ?>
        <div x-show="attachments.length > 0 || uploadingAttachment" x-cloak class="mb-6 flex flex-wrap items-center gap-2">
            <template x-for="attachment in attachments" :key="attachment.id">
                <span class="attachment-badge" :class="{ 'attachment-badge-offline': needsConnection(attachment) }">
                    <button type="button" class="attachment-badge-open" @click="openAttachment(attachment)" :title="attachmentLabel(attachment)">
                        <span x-show="needsConnection(attachment)" x-cloak x-icon="cloud-off"></span>
                        <span x-show="!needsConnection(attachment) && attachment.is_pdf" x-icon="file-text"></span>
                        <span x-show="!needsConnection(attachment) && !attachment.is_pdf" x-icon="paperclip"></span>
                        <span class="max-w-52 truncate" x-text="attachment.name"></span>
                    </button>
                    <button
                        x-show="canEditPage"
                        type="button"
                        class="attachment-badge-remove"
                        @click="removeAttachment(attachment)"
                        :aria-label="'Anhang ' + attachment.name + ' entfernen'"
                        title="Anhang entfernen"
                        x-icon="x"
                    ></button>
                </span>
            </template>
            <span x-show="uploadingAttachment" x-cloak class="text-sm" style="color: var(--color-text-muted);">Anhang wird hochgeladen…</span>
        </div>
        <p x-show="attachmentError" x-cloak x-text="attachmentError" class="mb-6 text-sm" style="color: var(--color-danger);" role="alert"></p>

        <div
            x-show="status === 'conflict'"
            class="mb-8 flex flex-col gap-3 rounded-lg p-4 text-base sm:flex-row sm:items-center sm:justify-between"
            style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);"
        >
            <span>Diese Seite wurde zwischenzeitlich anderswo geändert.</span>
            <div class="flex flex-wrap gap-3">
                <button @click="useServerVersion()" :disabled="pendingSave" class="underline text-sm font-medium">Serverfassung übernehmen</button>
                <button @click="keepMyVersion()" :disabled="pendingSave" class="underline text-sm font-medium">Meine Fassung behalten</button>
            </div>
        </div>

        <div x-show="editLockedElsewhere" x-cloak class="mb-8 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-accent) 12%, transparent);">
            Diese Notiz ist bereits in einem anderen Tab zum Bearbeiten geöffnet. Dieser Tab ist deshalb schreibgeschützt.
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
            <button type="button" data-editor-command="taskList" @click.prevent="toggleTaskList" class="toolbar-button" title="Checkliste" aria-label="Checkliste" x-icon="list-checks"></button>
            <button type="button" data-editor-command="blockquote" @click.prevent="toggleBlockquote" class="toolbar-button" title="Zitat" aria-label="Zitat" x-icon="quote"></button>
            <button type="button" data-editor-command="link" @click.prevent="editLink" class="toolbar-button" title="Link" aria-label="Link" x-icon="link"></button>
            <button type="button" data-editor-command="table" @click.prevent="insertTable" class="toolbar-button" title="Tabelle einfügen" aria-label="Tabelle einfügen" x-icon="table"></button>
            <?php /* Auf dem Handy gibt es weder Drag & Drop noch bequemes Einfügen aus der
                     Zwischenablage - Bilder kommen dort über Dateiauswahl und Kamera. */ ?>
            <button type="button" @click.prevent="pickImage" class="toolbar-button" title="Bild einfügen" aria-label="Bild einfügen" x-icon="image"></button>
            <button type="button" @click.prevent="pickCameraImage" class="toolbar-button md:hidden" title="Foto aufnehmen" aria-label="Foto aufnehmen" x-icon="camera"></button>
            <button type="button" @click.prevent="pickAttachment" class="toolbar-button" title="Anhang hochladen" aria-label="Anhang hochladen" x-icon="paperclip"></button>
            <input x-ref="imageInput" type="file" accept="image/*" class="hidden" @change="insertPickedImage">
            <input x-ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="insertPickedImage">
            <input x-ref="attachmentInput" type="file" multiple class="hidden" @change="uploadAttachment">
            <span x-show="inTable" x-cloak class="toolbar-divider"></span>
            <button x-show="inTable" x-cloak type="button" data-editor-command="tableAddRow" @click.prevent="addTableRow" class="toolbar-button" title="Zeile hinzufügen" aria-label="Zeile hinzufügen" x-icon="table-add-row"></button>
            <button x-show="inTable" x-cloak type="button" data-editor-command="tableAddCol" @click.prevent="addTableColumn" class="toolbar-button" title="Spalte hinzufügen" aria-label="Spalte hinzufügen" x-icon="table-add-col"></button>
            <button x-show="inTable" x-cloak type="button" data-editor-command="tableDelRow" @click.prevent="deleteTableRow" class="toolbar-button toolbar-button-danger" title="Zeile löschen" aria-label="Zeile löschen" x-icon="table-del-row"></button>
            <button x-show="inTable" x-cloak type="button" data-editor-command="tableDelCol" @click.prevent="deleteTableColumn" class="toolbar-button toolbar-button-danger" title="Spalte löschen" aria-label="Spalte löschen" x-icon="table-del-col"></button>
            <button x-show="inTable" x-cloak type="button" data-editor-command="tableDelete" @click.prevent="deleteTable" class="toolbar-button toolbar-button-danger" title="Tabelle löschen" aria-label="Tabelle löschen" x-icon="trash"></button>
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

    <?php /* PDF-Anhänge öffnen überlagert im Browser-Viewer (FR-NOTE-20). */ ?>
    <div
        x-show="pdfViewerUrl"
        x-cloak
        class="fixed inset-0 z-[110] flex flex-col p-3 sm:p-6"
        style="background-color: rgb(0 0 0 / 0.75);"
        @keydown.escape.window="closePdfViewer"
    >
        <div class="mx-auto flex h-full w-full max-w-5xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg);">
            <div class="flex items-center justify-between gap-3 border-b px-4 py-3" style="border-color: var(--color-border);">
                <p class="min-w-0 truncate font-medium" x-text="pdfViewerName"></p>
                <div class="flex shrink-0 items-center gap-1">
                    <a :href="pdfViewerUrl" target="_blank" rel="noopener" class="icon-action" title="In neuem Tab öffnen" aria-label="In neuem Tab öffnen" x-icon="share-2"></a>
                    <button type="button" @click="closePdfViewer" class="icon-action" title="Schließen" aria-label="Betrachter schließen" x-icon="x"></button>
                </div>
            </div>
            <?php /* Bewusst ohne jede Alpine-Direktive: Der CSP-Build lehnt das Auswerten
                     von Ausdrücken auf iframes grundsätzlich ab und bräche dabei die
                     Initialisierung der gesamten Seite ab. Quelle und Titel setzt
                     openAttachment() direkt am Element. */ ?>
            <iframe id="pdf-viewer-frame" title="PDF-Anhang" class="min-h-0 w-full flex-1" style="border: 0;"></iframe>
        </div>
    </div>

    <?php /* Vollbild-Betrachter für Bilder (nur Handy-Breite, siehe openImageViewer). */ ?>
    <div
        x-show="imageViewerSrc"
        x-cloak
        class="fixed inset-0 z-[120] flex items-center justify-center p-3"
        style="background-color: rgb(0 0 0 / 0.92);"
        @click.self="closeImageViewer"
        @keydown.escape.window="closeImageViewer"
        role="dialog"
        aria-modal="true"
    >
        <img :src="imageViewerSrc" :alt="imageViewerAlt" class="max-h-full max-w-full origin-center object-contain transition-transform duration-150" :style="imageViewerStyle()" @dblclick.prevent.stop="toggleImageZoom" @touchstart.stop="imageViewerTouchStart($event)" @touchmove.prevent.stop="imageViewerTouchMove($event)" @touchend.stop="imageViewerTouchEnd($event)" @touchcancel.stop="imageViewerTouchEnd($event)">
        <button
            type="button"
            @click.stop="closeImageViewer"
            class="sidebar-toggle absolute right-3 top-3 flex rounded-full"
            style="background-color: rgb(0 0 0 / 0.5); color: #ffffff;"
            aria-label="Vollbild schließen"
            x-icon="x"
        ></button>
    </div>

    <div x-show="compressionOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-5" style="background-color: rgb(0 0 0 / 0.45);" @click.self="closeCompressionDialog" @keydown.escape.window="closeCompressionDialog" role="dialog" aria-modal="true" aria-labelledby="compression-dialog-title">
        <form @submit.prevent="compressImages" class="w-full max-w-md rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4"><div><h2 id="compression-dialog-title" class="text-xl font-semibold">Bilder komprimieren</h2><p class="mt-1 text-sm" style="color: var(--color-text-muted);">Alle eingebetteten Bilder dieser Notiz serverseitig optimieren.</p></div><button type="button" @click="closeCompressionDialog" :disabled="compressionBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button></div>
            <div x-show="!compressionResult" x-cloak>
                <label for="compression-size" class="mt-5 block text-sm font-medium">Maximale Bildbreite</label>
                <select id="compression-size" x-model="compressionSize" :disabled="compressionBusy" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
                    <option value="original">Original · Abmessungen beibehalten</option>
                    <option value="screen">Bildschirm · maximal 1960 px</option>
                    <option value="medium">Mittel · maximal 1024 px</option>
                    <option value="small">Klein · maximal 800 px</option>
                </select>
                <div class="mt-5 flex items-center justify-between gap-3"><label for="compression-quality" class="text-sm font-medium">Qualität</label><span class="text-sm font-semibold" x-text="compressionQuality + ' %'"></span></div>
                <input id="compression-quality" x-model.number="compressionQuality" :disabled="compressionBusy" type="range" min="40" max="95" step="1" class="mt-2 w-full">
                <p class="mt-2 text-xs" style="color: var(--color-text-muted);">Empfehlung: 82 %. Die Qualität gilt für JPEG und WebP; PNG wird verlustfrei komprimiert.</p>
                <p class="mt-4 rounded-md p-3 text-xs" style="color: var(--color-danger); background: color-mix(in srgb, var(--color-danger) 9%, transparent);">Die Bilddateien werden ersetzt und sind nicht über den Versionsverlauf wiederherstellbar.</p>
            </div>
            <div x-show="compressionResult" x-cloak class="mt-5 rounded-lg p-4" style="background: var(--color-bg-subtle);"><p class="font-medium" style="color: var(--color-success);">Kompression abgeschlossen</p><p class="mt-1 text-sm" x-text="compressionResultLabel()"></p><p x-show="compressionResult && compressionResult.skipped > 0" class="mt-1 text-xs" style="color: var(--color-text-muted);"><span x-text="compressionResult.skipped"></span> Bild(er) waren bereits optimal oder nicht verfügbar.</p></div>
            <p x-show="compressionError" x-cloak x-text="compressionError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            <div class="mt-6 flex justify-end gap-2"><button x-show="!compressionResult" type="button" @click="closeCompressionDialog" :disabled="compressionBusy" class="btn btn-quiet">Abbrechen</button><button x-show="!compressionResult" type="submit" :disabled="compressionBusy" class="btn btn-primary" x-text="compressionBusy ? 'Komprimiere…' : 'Komprimieren'"></button><button x-show="compressionResult" type="button" @click="finishCompression" class="btn btn-primary">Fertig und neu laden</button></div>
        </form>
    </div>

    <div
        x-show="historyOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeHistory"
        @keydown.escape.window="closeHistory"
    >
        <div class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--color-border);">
                <div>
                    <h2 class="text-xl font-semibold">Versionsverlauf</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Snapshots nach 30 Minuten Pause oder Nutzerwechsel.</p>
                </div>
                <button type="button" @click="closeHistory" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="grid min-h-0 flex-1 gap-0 md:grid-cols-[16rem_1fr]">
                <div class="overflow-y-auto border-b md:border-b-0 md:border-r" style="border-color: var(--color-border);">
                    <p x-show="historyLoading" class="px-4 py-6 text-sm" style="color: var(--color-text-muted);">Lädt…</p>
                    <p x-show="!historyLoading && historyVersions.length === 0" class="px-4 py-6 text-sm" style="color: var(--color-text-muted);">Noch keine Versionen vorhanden.</p>
                    <template x-for="version in historyVersions" :key="version.id">
                        <button
                            type="button"
                            x-show="!historyLoading"
                            class="block w-full border-b px-4 py-3 text-left text-sm hover:opacity-80"
                            style="border-color: var(--color-border);"
                            :style="isSelectedVersion(version.id) ? 'background: color-mix(in srgb, var(--color-text) 8%, transparent);' : ''"
                            @click="selectVersion(version.id)"
                        >
                            <span class="block font-medium" x-text="versionLabel(version)"></span>
                            <span class="mt-1 block truncate" style="color: var(--color-text-muted);" x-text="version.preview"></span>
                        </button>
                    </template>
                </div>

                <div class="min-h-[14rem] overflow-y-auto px-5 py-4">
                    <p x-show="selectedVersionLoading" class="text-sm" style="color: var(--color-text-muted);">Version wird geladen…</p>
                    <p x-show="!selectedVersionLoading && !selectedVersion" class="text-sm" style="color: var(--color-text-muted);">Wähle eine Version zur Vorschau.</p>
                    <div x-show="!selectedVersionLoading && selectedVersion" x-cloak>
                        <p class="text-sm font-medium" x-text="versionLabel(selectedVersion)"></p>
                        <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm leading-relaxed" style="color: var(--color-text);" x-text="versionPreviewText()"></pre>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-between" style="border-color: var(--color-border);">
                <p x-show="historyError" x-text="historyError" class="text-sm" style="color: var(--color-danger);"></p>
                <p x-show="!historyError && !canRestoreVersions" class="text-sm" style="color: var(--color-text-muted);">Wiederherstellen ist nur dem Eigentümer möglich.</p>
                <div class="ml-auto flex gap-2">
                    <button type="button" @click="closeHistory" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Schließen</button>
                    <button
                        type="button"
                        x-show="canRestoreVersions"
                        @click="restoreSelectedVersion"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white"
                        style="background: var(--color-accent);"
                        :disabled="!selectedVersionId || restoringVersion"
                        x-text="restoringVersion ? 'Stelle wieder her…' : 'Wiederherstellen'"
                    ></button>
                </div>
            </div>
        </div>
    </div>
</div>
