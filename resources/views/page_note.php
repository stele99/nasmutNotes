<div class="note-page page-canvas page-content-canvas mx-auto px-4 pb-16 pt-2 sm:px-10 md:px-6 md:pt-5" x-data="noteEditorPage" data-page-id="<?= (int) $page['id'] ?>" data-page-title="<?= e((string) $page['title']) ?>" data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>" data-page-is-shared="<?= !empty($page['is_shared']) ? '1' : '0' ?>" data-page-encrypted="<?= !empty($page['is_encrypted']) ? '1' : '0' ?>" data-page-lat="<?= e((string) ($page['location_lat'] ?? '')) ?>" data-page-lon="<?= e((string) ($page['location_lon'] ?? '')) ?>" data-page-accuracy="<?= e((string) ($page['location_accuracy'] ?? '')) ?>" data-page-address="<?= e((string) ($page['location_label'] ?? '')) ?>">
    <div class="note-sticky-header page-toolbar flex items-center gap-2">
        <?php /* Rückweg zur Seitenauswahl - dieselbe Ebene, die mobil auch das
                 Wischen von links nach rechts erreicht (siehe workspaceShell). */ ?>
        <button type="button" @click="goBack()" class="icon-action flex shrink-0 items-center border p-2 md:hidden" style="border-color: var(--color-border);" title="Zurück zur Seitenauswahl" aria-label="Zurück zur Seitenauswahl" x-icon="chevron-left"></button>
        <?php /* Der Seitentitel steht mobil ohnehin direkt darunter als Überschrift -
                 der Breadcrumb kostet dort nur Höhe und entfällt deshalb. Ebenso
                 der Hinweis „Geteilt" und die Personenkreise: Den Zustand trägt
                 mobil der eingefärbte Teilen-Schalter, und der Kopf bleibt so
                 einzeilig (NFR-UI-25). Darüber kürzt jeder Bestandteil mit
                 Auslassungspunkten, statt umzubrechen: Eine zweite Zeile machte
                 den Kopf höher und verschöbe die darunter klebende
                 Werkzeugleiste. */ ?>
        <span class="hidden min-w-0 flex-1 items-center gap-2 md:flex">
            <?php $nbIcon = !empty($page['is_shared']) ? 'share-2' : ($page['notebook_icon'] ?? 'book-open'); ?>
            <?php $nbColor = !empty($page['is_shared']) ? 'var(--color-text-muted)' : ($page['notebook_color'] ?? 'var(--color-text-muted)'); ?>
            <span class="shrink-0" style="color: <?= e($nbColor) ?>;" x-icon="<?= e($nbIcon) ?>"></span>
            <span class="min-w-0 truncate"><?= e(!empty($page['is_shared']) ? 'Geteilt' : (string) ($page['notebook_name'] ?? 'Nicht zugewiesen')) ?></span>
            <span class="shrink-0" style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
            <span class="min-w-0 truncate" x-text="pageTitle"></span>
        </span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <span x-show="isShared" x-cloak class="hidden items-center gap-1.5 text-sm md:inline-flex" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>
                Geteilt · <span x-text="permissionLabel()"></span>
            </span>
            <span x-show="ownedAndShared()" x-cloak class="hidden items-center gap-1.5 text-sm md:inline-flex" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>Geteilt
            </span>
            <?php include __DIR__ . '/partials/page_writers.php'; ?>
            <button
                x-show="status === 'conflict'"
                x-cloak
                type="button"
                @click="openConflictDialog"
                class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5"
                style="border-color: var(--color-danger); color: var(--color-danger);"
                title="Sync-Konflikt: beide Fassungen ansehen und entscheiden"
                aria-label="Sync-Konflikt"
            >
                <span x-icon="triangle-alert"></span><span class="hidden lg:inline">Sync-Konflikt</span>
            </button>
            <?php /* Löschen steht bewusst nur als Symbol da - der rote Papierkorb
                     ist eindeutig genug und die Leiste ist schon gut gefüllt. */ ?>
            <button x-show="!isShared && canEditPage" type="button" @click="trashPage" class="icon-action icon-action-danger flex items-center border p-2" style="border-color: var(--color-border); color: var(--color-danger);" title="In den Papierkorb" aria-label="In den Papierkorb" x-icon="trash"></button>
            <button x-show="!isEncrypted()" type="button" @click="openHistory" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Versionsverlauf" aria-label="Versionsverlauf">
                <span x-icon="history"></span><span class="hidden lg:inline">Verlauf</span>
            </button>
            <?php /* Druckt allein Titel und Inhalt; das Ausblenden der Oberfläche
                     übernimmt das Druck-Stylesheet (FR-NOTE-27). */ ?>
            <button x-show="!isEncrypted() || isCryptoUnlocked()" type="button" @click="printNote" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Notiz drucken" aria-label="Notiz drucken">
                <span x-icon="printer"></span><span class="hidden lg:inline">Drucken</span>
            </button>
            <button x-show="!isShared && canEditPage && !isEncrypted()" type="button" @click="openCompressionDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Bilder komprimieren" aria-label="Bilder komprimieren">
                <span x-icon="image"></span><span class="hidden lg:inline">Komprimieren</span>
            </button>
            <button x-show="!isShared && canEditPage" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" :class="shareButtonClass()" style="border-color: var(--color-border);" :title="shareButtonLabel()" :aria-label="shareButtonLabel()">
                <span x-icon="share-2"></span><span class="hidden lg:inline">Teilen</span>
            </button>
            <div x-show="!isShared && canEditPage" class="relative" @click.outside="encryptionMenuOpen = false">
                <button type="button" @click="handleEncryptionButton" :disabled="!isEncrypted() && attachments.length > 0" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" :class="isEncrypted() ? 'note-encryption-active' : ''" style="border-color: var(--color-border);" :title="encryptionButtonLabel()" :aria-label="encryptionButtonLabel()" :aria-expanded="encryptionMenuOpen">
                    <span x-show="!isEncrypted()" x-icon="lock-open"></span>
                    <span x-show="isEncrypted()" x-cloak x-icon="lock"></span>
                    <span class="hidden lg:inline" x-text="isEncrypted() ? (isCryptoUnlocked() ? 'Entsperrt' : 'Gesperrt') : 'Verschlüsseln'"></span>
                </button>
                <div x-show="encryptionMenuOpen" x-cloak class="popup-menu" @keydown.escape.window="encryptionMenuOpen = false">
                    <p class="px-3 py-2 text-xs font-medium" style="color: var(--color-text-muted);" x-text="encryptionButtonLabel()"></p>
                    <button type="button" @click="lockEncryptedNote" class="popup-menu-button"><span x-icon="lock"></span>Sperren</button>
                    <button type="button" @click="openCryptoDialog('rewrap')" class="popup-menu-button"><span x-icon="key-round"></span>Kennwort ändern</button>
                    <button type="button" @click="openCryptoDialog('decrypt')" class="popup-menu-button popup-menu-danger"><span x-icon="lock-open"></span>Verschlüsselung aufheben</button>
                </div>
            </div>
        </div>
    </div>
    <div class="pt-4 md:pt-10">
        <div class="flex flex-col gap-1 md:flex-row md:items-start md:justify-between md:gap-4" :class="(attachments.length > 0 || uploadingAttachment) ? 'mb-6 md:mb-10' : 'mb-3 md:mb-6'">
            <div class="min-w-0">
                <?php /* leading-tight: siehe page_log.php - `truncate` schnitte
                         sonst die Unterlängen ab. */ ?>
                <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text truncate text-4xl font-semibold leading-tight tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
                <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="page-title-input w-full min-w-0 text-4xl font-semibold tracking-tight sm:text-5xl">
                 <div class="note-print-hide">
                     <?php include __DIR__ . '/partials/page_location.php'; ?>
                     <?php include __DIR__ . '/partials/shared_page_meta.php'; ?>
                 </div>
            </div>
            <div class="note-print-hide flex shrink-0 items-start gap-3">
                <div class="text-left text-xs md:text-right md:text-sm" style="color: var(--color-text-muted);">
                    <p x-text="statusLabel()"></p>
                </div>
            </div>
        </div>

        <?php /* Anhänge der Seite als Badges direkt unter der Überschrift (FR-NOTE-19).
                 Klick öffnet bzw. lädt herunter, das × entfernt den Anhang. Anhänge
                 über dem Offline-Limit sind ohne Netz als „nur online" markiert
                 (FR-OFFLINE-06). */ ?>
        <div x-show="!isEncrypted() && (attachments.length > 0 || uploadingAttachment)" x-cloak class="note-print-hide mb-6 flex flex-wrap items-center gap-2">
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
            x-cloak
            class="mb-8 flex flex-col gap-3 rounded-lg p-4 text-base sm:flex-row sm:items-center sm:justify-between"
            style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);"
        >
            <span>Diese Seite wurde zwischenzeitlich anderswo geändert.</span>
            <div class="flex flex-wrap gap-3">
                <button type="button" @click="openConflictDialog" class="underline text-sm font-medium" style="color: var(--color-danger);">Beide Fassungen vergleichen</button>
                <button @click="useServerVersion()" :disabled="pendingSave" class="underline text-sm font-medium">Serverfassung übernehmen</button>
                <button x-show="canKeepConflict()" @click="keepMyVersion()" :disabled="pendingSave" class="underline text-sm font-medium">Meine Fassung behalten</button>
            </div>
        </div>

        <div x-show="editLockedElsewhere" x-cloak class="mb-8 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-accent) 12%, transparent);">
            Diese Notiz ist bereits in einem anderen Tab zum Bearbeiten geöffnet. Dieser Tab ist deshalb schreibgeschützt.
        </div>

        <div x-show="status === 'invalid'" x-cloak class="mb-8 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert">
            <span x-text="saveError"></span>
        </div>

        <div x-show="canEditPage && (!isEncrypted() || isCryptoUnlocked())" class="note-sticky-toolbar editor-toolbar mb-5 flex flex-wrap items-center gap-1 border-b pb-4" :class="toolbarExpandedClass()" style="border-color: var(--color-border);" x-ref="toolbar">
            <button type="button" data-editor-command="bold" @click.prevent="toggleBold" class="toolbar-button" title="Fett" aria-label="Fett" x-icon="bold"></button>
            <button type="button" data-editor-command="italic" @click.prevent="toggleItalic" class="toolbar-button" title="Kursiv" aria-label="Kursiv" x-icon="italic"></button>
            <button type="button" data-editor-command="strike" @click.prevent="toggleStrike" class="toolbar-button toolbar-text toolbar-more" title="Durchgestrichen" aria-label="Durchgestrichen">S</button>
            <button type="button" data-editor-command="code" @click.prevent="toggleCode" class="toolbar-button toolbar-more" title="Code (inline)" aria-label="Code (inline)" x-icon="code"></button>
            <button type="button" data-editor-command="codeBlock" @click.prevent="toggleCodeBlock" class="toolbar-button toolbar-more" title="Codeblock" aria-label="Codeblock" x-icon="square-code"></button>
            <span class="toolbar-divider toolbar-more"></span>
            <button type="button" data-editor-command="heading1" @click.prevent="toggleHeading1" class="toolbar-button toolbar-more" title="Überschrift 1" aria-label="Überschrift 1" x-icon="heading-1"></button>
            <button type="button" data-editor-command="heading2" @click.prevent="toggleHeading2" class="toolbar-button" title="Überschrift 2" aria-label="Überschrift 2" x-icon="heading-2"></button>
            <button type="button" data-editor-command="bulletList" @click.prevent="toggleBulletList" class="toolbar-button" title="Aufzählung" aria-label="Aufzählung" x-icon="list"></button>
            <button type="button" data-editor-command="taskList" @click.prevent="toggleTaskList" class="toolbar-button" title="Checkliste" aria-label="Checkliste" x-icon="list-checks"></button>
            <button type="button" data-editor-command="blockquote" @click.prevent="toggleBlockquote" class="toolbar-button toolbar-more" title="Zitat" aria-label="Zitat" x-icon="quote"></button>
            <button type="button" data-editor-command="link" @click.prevent="editLink" class="toolbar-button toolbar-more" title="Link" aria-label="Link" x-icon="link"></button>
            <button type="button" data-editor-command="table" @click.prevent="insertTable" class="toolbar-button toolbar-more" title="Tabelle einfügen" aria-label="Tabelle einfügen" x-icon="table"></button>
            <?php /* Auf dem Handy gibt es weder Drag & Drop noch bequemes Einfügen aus der
                     Zwischenablage - Bilder kommen dort über Dateiauswahl und Kamera. */ ?>
            <button x-show="!isEncrypted()" type="button" @click.prevent="pickImage" class="toolbar-button toolbar-more" title="Bild einfügen" aria-label="Bild einfügen" x-icon="image"></button>
            <button x-show="!isEncrypted()" type="button" @click.prevent="pickCameraImage" class="toolbar-button md:hidden" title="Foto aufnehmen" aria-label="Foto aufnehmen" x-icon="camera"></button>
            <button x-show="!isEncrypted()" type="button" @click.prevent="pickAttachment" class="toolbar-button toolbar-more" title="Anhang hochladen" aria-label="Anhang hochladen" x-icon="paperclip"></button>
            <?php /* Diktat: Der aufbereitete Text wird an der Einfügemarke eingesetzt
                     (FR-VOICE-02). Derselbe Knopf beendet die Aufnahme wieder. */ ?>
            <?php if (!empty($voiceEnabled)): ?>
                <button type="button" x-show="voiceSupported && canEditPage && !isEncrypted()" x-cloak @click.prevent="toggleVoice" :disabled="voiceStatus === 'processing'" class="toolbar-button" :class="voiceStatus === 'recording' ? 'is-recording' : ''" :title="voiceStatus === 'recording' ? 'Aufnahme beenden' : 'Diktat einfügen'" aria-label="Diktat einfügen">
                    <span x-show="voiceStatus !== 'recording'" x-icon="mic"></span>
                    <span x-show="voiceStatus === 'recording'" x-cloak x-icon="square"></span>
                </button>
            <?php endif; ?>
            <?php if (!empty($aiEnabled)): ?>
                <button x-show="!isEncrypted()" type="button" @click.prevent="openAiRewriteDialog" :disabled="aiBusy || !isOnline" class="toolbar-button toolbar-more" title="Text mit KI korrigieren und strukturieren" aria-label="Text mit KI korrigieren und strukturieren" x-icon="wand-sparkles"></button>
            <?php endif; ?>
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
            <button type="button" data-editor-command="redo" @click.prevent="redo" class="toolbar-button toolbar-more" title="Wiederholen" aria-label="Wiederholen" x-icon="redo"></button>
            <?php /* Klappt auf dem Handy die übrigen Werkzeuge auf (NFR-UI-24).
                     Ab 768 px steht ohnehin alles in der Leiste. */ ?>
            <?php /* touchstart/mousedown halten den Fokus im Editor: Ohne sie
                     nähme der Schalter ihn an sich, die Textauswahl ginge
                     verloren und die Tastatur führe ein. Die übrigen Werkzeuge
                     fallen damit nicht auf, weil sie den Editor über
                     runEditorCommand() ohnehin wieder fokussieren. */ ?>
            <button type="button" @touchstart="rememberEditorFocus" @mousedown.prevent="rememberEditorFocus" @click.prevent="toggleToolbarMore" class="toolbar-button md:hidden" :class="toolbarMoreButtonClass()" :aria-expanded="toolbarExpanded" :title="toolbarMoreLabel()" :aria-label="toolbarMoreLabel()" x-icon="more-horizontal"></button>
        </div>
        <p x-show="imageUploadError" x-text="imageUploadError" class="note-print-hide mb-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
        <?php if (!empty($voiceEnabled)): ?>
            <div x-show="!isEncrypted()" class="note-print-hide mb-4"><?php include __DIR__ . '/partials/voice_panel.php'; ?></div>
        <?php endif; ?>
        <section x-show="isEncrypted() && !isCryptoUnlocked()" x-cloak class="note-lock-screen" aria-labelledby="note-lock-title">
            <span class="note-lock-icon" x-icon="lock:size-8"></span>
            <h2 id="note-lock-title" class="mt-4 text-xl font-semibold">Verschlüsselte Notiz</h2>
            <p x-show="cryptoStatus === 'locked'" class="mt-2 max-w-md text-sm" style="color: var(--color-text-muted);">Der Inhalt liegt auf diesem Gerät nur als Ciphertext vor. Gib das Notizkennwort ein, um ihn lokal im Browser zu entschlüsseln.</p>
            <p x-show="cryptoStatus === 'error'" x-text="cryptoError" class="mt-2 max-w-md text-sm" style="color: var(--color-danger);" role="alert"></p>
            <button x-show="cryptoStatus === 'locked'" type="button" @click="openCryptoDialog('unlock')" class="btn btn-primary mt-5"><span x-icon="lock-open"></span>Entsperren</button>
        </section>
        <div x-show="!isEncrypted() || isCryptoUnlocked()" class="prose-editor" x-ref="editor"></div>
        <div x-show="linkMenuOpen" x-cloak @click.outside="closeLinkMenu" @keydown.escape.window="closeLinkMenu" class="link-action-menu" :style="linkMenuStyle">
            <button type="button" @click="openActiveLink" class="link-action-button">Öffnen</button>
            <button x-show="canEditPage" type="button" @click="editActiveLink" class="link-action-button">Bearbeiten</button>
        </div>
        <p x-show="updatedAt" class="note-print-hide mt-12 pb-2 text-center text-xs" style="color: var(--color-text-muted);" x-text="lastEditedLabel()"></p>
    </div>

    <?php include __DIR__ . '/partials/page_location_dialog.php'; ?>

    <div x-show="cryptoDialogOpen" x-cloak class="fixed inset-0 z-[130] flex items-center justify-center p-4" style="background-color: rgb(0 0 0 / 0.5);" @click.self="closeCryptoDialog" @keydown.escape.window="closeCryptoDialog" @keydown.tab="trapCryptoDialogFocus" role="dialog" aria-modal="true" aria-labelledby="note-crypto-dialog-title">
        <form x-ref="cryptoDialog" @submit.prevent="submitCryptoDialog" class="w-full max-w-lg rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="note-crypto-dialog-title" class="text-xl font-semibold" x-text="encryptionDialogTitle()"></h2>
                    <p x-show="cryptoDialogMode === 'unlock'" class="mt-1 text-sm" style="color: var(--color-text-muted);">Entschlüsselung und Schlüsselableitung erfolgen ausschließlich in diesem Browser.</p>
                    <p x-show="cryptoDialogMode === 'encrypt'" class="mt-1 text-sm" style="color: var(--color-text-muted);">Der Server erhält danach nur noch den verschlüsselten Krypto-Umschlag.</p>
                    <p x-show="cryptoDialogMode === 'rewrap'" class="mt-1 text-sm" style="color: var(--color-text-muted);">Nur die Kennworthülle wird ersetzt. Der verschlüsselte Inhalt bleibt unverändert.</p>
                    <p x-show="cryptoDialogMode === 'decrypt'" class="mt-1 text-sm" style="color: var(--color-text-muted);">Der Inhalt wird wieder als lesbarer Klartext auf dem Server gespeichert.</p>
                </div>
                <button type="button" @click="closeCryptoDialog" :disabled="cryptoBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div x-show="cryptoDialogMode === 'unlock'" class="mt-5">
                <label for="note-crypto-password" class="block text-sm font-medium">Notizkennwort</label>
                <input id="note-crypto-password" x-model="cryptoPassword" type="password" autocomplete="current-password" maxlength="1024" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>

            <div x-show="cryptoDialogMode === 'rewrap'" class="mt-5">
                <label for="note-crypto-current-password" class="block text-sm font-medium">Bisheriges Kennwort</label>
                <input id="note-crypto-current-password" x-model="cryptoPasswordCurrent" type="password" autocomplete="current-password" maxlength="1024" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>

            <div x-show="cryptoDialogMode === 'encrypt' || cryptoDialogMode === 'rewrap'" class="mt-5 space-y-4">
                <div>
                    <label for="note-crypto-new-password" class="block text-sm font-medium" x-text="cryptoDialogMode === 'encrypt' ? 'Kennwort' : 'Neues Kennwort'"></label>
                    <input id="note-crypto-new-password" x-model="cryptoPassword" type="password" autocomplete="new-password" maxlength="1024" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);" aria-describedby="note-crypto-password-hint">
                </div>
                <div>
                    <label for="note-crypto-confirm-password" class="block text-sm font-medium">Kennwort wiederholen</label>
                    <input id="note-crypto-confirm-password" x-model="cryptoPasswordConfirm" type="password" autocomplete="new-password" maxlength="1024" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
                </div>
                <p id="note-crypto-password-hint" x-show="cryptoPasswordHint()" x-text="cryptoPasswordHint()" class="text-xs" style="color: var(--color-text-muted);"></p>
                <p class="rounded-lg p-3 text-sm" style="background: var(--color-bg-subtle);">Verwende mindestens 12 Zeichen, besser eine lange einzigartige Passphrase. Leerzeichen am Anfang und Ende gehören zum Kennwort.</p>
            </div>

            <div x-show="cryptoDialogMode === 'encrypt'" class="mt-5 rounded-lg p-4 text-sm" style="background: color-mix(in srgb, var(--color-accent) 9%, transparent);">
                <p><strong>Keine Wiederherstellung:</strong> Ein verlorenes Kennwort kann weder durch den Server noch durch Administratoren ersetzt werden.</p>
                <p class="mt-2">Die Verschlüsselung schützt nicht vor Schadcode im ausgelieferten JavaScript, kompromittierten Geräten oder alten Klartextkopien in Backups und Browser-Snapshots. Titel, Standort, Zeitpunkte und Größe bleiben sichtbar.</p>
            </div>
            <div x-show="cryptoDialogMode === 'decrypt'" class="mt-5 rounded-lg p-4 text-sm" style="background: color-mix(in srgb, var(--color-danger) 9%, transparent);">
                Suche, Export, Versionsverlauf, KI und künftige Backups können den Inhalt danach wieder als Klartext verarbeiten.
            </div>

            <label x-show="cryptoDialogMode === 'encrypt' || cryptoDialogMode === 'decrypt'" class="mt-5 flex items-start gap-3 text-sm">
                <input x-model="cryptoAcknowledged" type="checkbox" class="mt-1">
                <span x-show="cryptoDialogMode === 'encrypt'">Ich verstehe, dass der Inhalt bei Kennwortverlust endgültig unzugänglich ist.</span>
                <span x-show="cryptoDialogMode === 'decrypt'">Ich bestätige, dass der Server den Notizinhalt künftig wieder lesen kann.</span>
            </label>

            <p x-show="cryptoDialogError" x-text="cryptoDialogError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="closeCryptoDialog" :disabled="cryptoBusy" class="btn btn-quiet">Abbrechen</button>
                <button type="submit" :disabled="cryptoBusy" class="btn btn-primary" x-text="cryptoActionLabel()"></button>
            </div>
        </form>
    </div>

    <?php if (!empty($aiEnabled)): ?>
        <div x-show="aiOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-3 sm:p-5" style="background-color: rgb(0 0 0 / 0.45);" @click.self="closeAiRewriteDialog" @keydown.escape.window="closeAiRewriteDialog" role="dialog" aria-modal="true" aria-labelledby="ai-rewrite-title">
            <div class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
                <div class="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
                    <div><h2 id="ai-rewrite-title" class="text-xl font-semibold">Text mit KI überarbeiten</h2><p class="mt-1 text-sm" style="color: var(--color-text-muted);">Korrigiert Sprache und gliedert die gesamte Notiz mit Absätzen und Überschriften.</p></div>
                    <button type="button" @click="closeAiRewriteDialog" :disabled="aiBusy || aiApplying" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                    <label for="ai-rewrite-mode" class="block text-sm font-medium">Art der Überarbeitung</label>
                    <select id="ai-rewrite-mode" x-model="aiMode" @change="resetAiSuggestion" :disabled="aiBusy || aiApplying" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="normal">Normal: Fehlerkorrektur und Typografie</option>
                        <option value="inviting">Einladend: Ergänzung mit Emojis</option>
                    </select>
                    <div x-show="!aiSuggestion && !aiBusy" class="mt-4 rounded-lg p-4 text-sm" style="background: var(--color-bg-subtle);">
                        Der Notiztext wird an den konfigurierten KI-Dienst übertragen. Bilder, Tabellen, Codeblöcke, Checklisten, Links und Inline-Code bleiben dabei exakt unverändert; nur der übrige Text wird überarbeitet.
                    </div>
                    <div x-show="aiBusy" x-cloak class="py-10 text-center"><span class="text-sm" style="color: var(--color-text-muted);">Die KI erstellt einen Vorschlag…</span></div>
                    <div x-show="aiSuggestion" x-cloak>
                        <p class="mb-2 text-sm font-medium">Vorschau</p>
                        <div class="max-h-[45vh] overflow-y-auto whitespace-pre-wrap rounded-lg border p-4 text-sm leading-relaxed" style="border-color: var(--color-border); background: var(--color-bg-subtle);" x-text="aiPreview"></div>
                        <p class="mt-3 text-xs" style="color: var(--color-text-muted);">Bitte prüfe den Vorschlag. Erst „Übernehmen“ ersetzt den bisherigen Text; die vorherige Fassung bleibt im Versionsverlauf.</p>
                    </div>
                    <p x-show="aiError" x-cloak x-text="aiError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
                    <button type="button" @click="closeAiRewriteDialog" :disabled="aiBusy || aiApplying" class="btn btn-quiet">Abbrechen</button>
                    <button x-show="!aiSuggestion" type="button" @click="requestAiRewrite" :disabled="aiBusy || aiApplying" class="btn btn-primary" x-text="aiBusy ? 'Erstellt…' : 'Vorschlag erstellen'"></button>
                    <button x-show="aiSuggestion" x-cloak type="button" @click="requestAiRewrite" :disabled="aiBusy || aiApplying" class="btn btn-secondary">Neu erstellen</button>
                    <button x-show="aiSuggestion" x-cloak type="button" @click="applyAiRewrite" :disabled="aiBusy || aiApplying" class="btn btn-primary" x-text="aiApplying ? 'Übernimmt…' : 'Übernehmen'"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php /* PDF-Anhänge öffnen überlagert im Browser-Viewer (FR-NOTE-20). */ ?>
    <div
        x-show="pdfViewerUrl"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="PDF-Anhang"
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
            <div x-show="compressionResult" x-cloak class="mt-5 rounded-lg p-4" style="background: var(--color-bg-subtle);"><p class="font-medium" style="color: var(--color-success);">Kompression abgeschlossen</p><p class="mt-1 text-sm" x-text="compressionResultLabel()"></p><p x-show="compressionSkippedCount() > 0" class="mt-1 text-xs" style="color: var(--color-text-muted);"><span x-text="compressionSkippedCount()"></span> Bild(er) waren bereits optimal oder nicht verfügbar.</p></div>
            <p x-show="compressionError" x-cloak x-text="compressionError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            <div class="mt-6 flex justify-end gap-2"><button x-show="!compressionResult" type="button" @click="closeCompressionDialog" :disabled="compressionBusy" class="btn btn-quiet">Abbrechen</button><button x-show="!compressionResult" type="submit" :disabled="compressionBusy" class="btn btn-primary" x-text="compressionBusy ? 'Komprimiere…' : 'Komprimieren'"></button><button x-show="compressionResult" type="button" @click="finishCompression" class="btn btn-primary">Fertig und neu laden</button></div>
        </form>
    </div>

    <div
        x-show="historyOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeHistory"
        @keydown.escape.window="closeHistory"
        role="dialog"
        aria-modal="true"
        aria-labelledby="note-history-title"
    >
        <div class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--color-border);">
                <div>
                    <h2 id="note-history-title" class="text-xl font-semibold">Versionen vergleichen</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Wähle zwei Fassungen. Entfernte Inhalte erscheinen rot, hinzugefügte grün.</p>
                </div>
                <button type="button" @click="closeHistory" :disabled="restoringVersion" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
                <p x-show="historyLoading" class="py-8 text-center text-sm" style="color: var(--color-text-muted);">Versionsverlauf wird geladen…</p>
                <p x-show="!historyLoading && historyVersions.length === 0" class="py-8 text-center text-sm" style="color: var(--color-text-muted);">Noch keine gespeicherten Versionen vorhanden.</p>

                <div x-show="!historyLoading && historyVersions.length > 0" x-cloak>
                    <div class="grid gap-4 md:grid-cols-2">
                        <section class="min-w-0">
                            <label for="history-left-version" class="block text-sm font-medium">Ausgangsversion</label>
                            <select id="history-left-version" x-model="historyLeftId" @change="selectHistoryLeft" :disabled="restoringVersion" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                                <template x-for="version in historyVersions" :key="version.id"><option :value="versionIdValue(version)" x-text="versionLabel(version)"></option></template>
                            </select>
                            <div class="mt-3 min-h-44 max-h-72 overflow-auto rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                                <p x-show="historyLeftLoading" class="text-sm" style="color: var(--color-text-muted);">Version wird geladen…</p>
                                <pre x-show="!historyLeftLoading && historyLeftDocument" class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed" x-text="historyDocumentPreview('left')"></pre>
                            </div>
                        </section>

                        <section class="min-w-0">
                            <label for="history-right-version" class="block text-sm font-medium">Vergleichsversion</label>
                            <select id="history-right-version" x-model="historyRightId" @change="selectHistoryRight" :disabled="restoringVersion" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                                <option value="current" x-text="historyCurrentLabel()"></option>
                                <template x-for="version in historyVersions" :key="version.id"><option :value="versionIdValue(version)" x-text="versionLabel(version)"></option></template>
                            </select>
                            <div class="mt-3 min-h-44 max-h-72 overflow-auto rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                                <p x-show="historyRightLoading" class="text-sm" style="color: var(--color-text-muted);">Version wird geladen…</p>
                                <pre x-show="!historyRightLoading && historyRightDocument" class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed" x-text="historyDocumentPreview('right')"></pre>
                            </div>
                        </section>
                    </div>

                    <section class="mt-6 border-t pt-5" style="border-color: var(--color-border);">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">Unterschiede</h3><span class="text-sm" style="color: var(--color-text-muted);" x-text="historyDiffSummary()"></span></div>
                        <div x-show="historyLeftDocument && historyRightDocument" class="note-history-diff overflow-hidden rounded-lg border" style="border-color: var(--color-border);">
                            <template x-for="row in historyDiffRows" :key="row.key">
                                <div :class="historyDiffRowClass(row)"><span class="note-history-diff-marker" x-text="row.marker"></span><span class="note-history-diff-text" x-text="row.text"></span></div>
                            </template>
                        </div>
                    </section>
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
                        class="btn btn-primary"
                        :disabled="!historyLeftId || !historyLeftDocument || restoringVersion"
                        x-text="restoringVersion ? 'Stelle wieder her…' : 'Ausgangsversion wiederherstellen'"
                    ></button>
                </div>
            </div>
        </div>
    </div>

    <?php /* Konflikt-Dialog: Auflösung findet ausschließlich hier statt (nicht in
             den Einstellungen), weil nur hier beide Fassungen im Kontext der Notiz
             nebeneinander sichtbar sind. Erreichbar über den roten Knopf oben in
             der Werkzeugleiste, sobald status === 'conflict'. */ ?>
    <div
        x-show="conflictDialogOpen"
        x-cloak
        class="fixed inset-0 z-[100] flex items-center justify-center p-3 sm:p-5"
        style="background-color: rgb(0 0 0 / 0.45);"
        @click.self="closeConflictDialog"
        @keydown.escape.window="closeConflictDialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="note-conflict-title"
    >
        <div class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-4xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--color-border);">
                <div>
                    <h2 id="note-conflict-title" class="text-xl font-semibold" style="color: var(--color-danger);">Sync-Konflikt</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Diese Notiz wurde zwischenzeitlich anderswo geändert. Wähle, welche Fassung erhalten bleiben soll.</p>
                </div>
                <button type="button" @click="closeConflictDialog" :disabled="pendingSave" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <section class="min-w-0">
                        <p class="text-sm font-medium">Meine Fassung <span x-show="updatedAt" class="font-normal" style="color: var(--color-text-muted);">· zuletzt lokal bearbeitet</span></p>
                        <div class="mt-2 min-h-44 max-h-96 overflow-auto rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed" x-text="conflictDocumentText(myConflictDocument())"></pre>
                        </div>
                    </section>

                    <section class="min-w-0">
                        <p class="text-sm font-medium">
                            Serverfassung
                            <span class="font-normal" style="color: var(--color-text-muted);" x-text="serverConflictVersionLabel()"></span>
                        </p>
                        <div class="mt-2 min-h-44 max-h-96 overflow-auto rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed" x-text="serverConflictDocumentText()"></pre>
                        </div>
                    </section>
                </div>
                <p x-show="saveError" x-cloak x-text="saveError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            </div>

            <div class="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-end" style="border-color: var(--color-border);">
                <button type="button" @click="useServerVersion()" :disabled="pendingSave" class="btn btn-secondary">Serverfassung übernehmen</button>
                <button type="button" x-show="canKeepConflict()" @click="keepMyVersion()" :disabled="pendingSave" class="btn btn-primary" x-text="pendingSave ? 'Wird gespeichert…' : 'Meine Fassung behalten'"></button>
            </div>
        </div>
    </div>
</div>
