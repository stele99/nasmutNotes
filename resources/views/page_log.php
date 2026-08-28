<div class="log-page page-canvas page-content-canvas mx-auto px-4 pb-16 pt-2 sm:px-10 md:px-6 md:pt-5" x-data="logPage" data-page-id="<?= (int) $page['id'] ?>" data-page-title="<?= e((string) $page['title']) ?>" data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>" data-page-lat="<?= e((string) ($page['location_lat'] ?? '')) ?>" data-page-lon="<?= e((string) ($page['location_lon'] ?? '')) ?>" data-page-accuracy="<?= e((string) ($page['location_accuracy'] ?? '')) ?>" data-page-address="<?= e((string) ($page['location_label'] ?? '')) ?>" data-page-is-shared="<?= !empty($page['is_shared']) ? '1' : '0' ?>">
    <div class="note-sticky-header page-toolbar flex items-center gap-2">
        <button type="button" @click="goBack()" class="icon-action flex shrink-0 items-center border p-2 md:hidden" style="border-color: var(--color-border);" title="Zurück zur Seitenauswahl" aria-label="Zurück zur Seitenauswahl" x-icon="chevron-left"></button>
        <span class="hidden min-w-0 flex-1 items-center gap-2 md:flex">
            <?php $nbIcon = !empty($page['is_shared']) ? 'share-2' : ($page['notebook_icon'] ?? 'book-open'); ?>
            <?php $nbColor = !empty($page['is_shared']) ? 'var(--color-text-muted)' : ($page['notebook_color'] ?? 'var(--color-text-muted)'); ?>
            <span class="shrink-0" style="color: <?= e($nbColor) ?>;" x-icon="<?= e($nbIcon) ?>"></span>
            <span class="min-w-0 truncate"><?= e(!empty($page['is_shared']) ? 'Geteilt' : (string) ($page['notebook_name'] ?? 'Nicht zugewiesen')) ?></span>
            <span class="shrink-0" style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
            <span class="min-w-0 truncate" x-text="pageTitle"></span>
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
            <?php /* Löschen steht bewusst nur als Symbol da - der rote Papierkorb
                     ist eindeutig genug und die Leiste ist schon gut gefüllt. */ ?>
            <button x-show="!isShared && canEditPage" type="button" @click="trashPage" class="icon-action icon-action-danger flex items-center border p-2" style="border-color: var(--color-border); color: var(--color-danger);" title="In den Papierkorb" aria-label="In den Papierkorb" x-icon="trash"></button>
            <button x-show="canEditPage" type="button" @click="openColumnDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Spalten verwalten" aria-label="Spalten verwalten">
                <span x-icon="table"></span><span class="hidden lg:inline">Spalten</span>
            </button>
            <?php /* Export als Tabelle (FR-LOG-12). Das Format steht im
                     Aufklappmenü, damit der Kopf nur einen Schalter kostet. */ ?>
            <div class="relative" @click.outside="closeExportMenu">
                <button type="button" @click="toggleExportMenu" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Logbuch exportieren" aria-label="Logbuch exportieren" :aria-expanded="exportMenuOpen">
                    <span x-icon="download"></span><span class="hidden lg:inline">Export</span>
                </button>
                <div x-show="exportMenuOpen" x-cloak class="popup-menu" @keydown.escape.window="closeExportMenu">
                    <p class="px-3 py-2 text-xs font-medium" style="color: var(--color-text-muted);">Logbuch exportieren</p>
                    <button type="button" @click="exportLog('csv')" class="popup-menu-button"><span x-icon="file-text"></span>CSV</button>
                    <button type="button" @click="exportLog('xlsx')" class="popup-menu-button"><span x-icon="table"></span>Excel (XLSX)</button>
                    <button type="button" @click="exportLog('xml')" class="popup-menu-button"><span x-icon="code"></span>XML</button>
                </div>
            </div>
            <button x-show="!isShared && canEditPage" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Seite teilen" aria-label="Seite teilen">
                <span x-icon="share-2"></span><span class="hidden lg:inline">Teilen</span>
            </button>
        </div>
    </div>

    <div class="pt-4 md:pt-10">
        <div class="mb-6">
            <?php /* leading-tight: `truncate` blendet aus, was über die Zeilenhöhe
                     hinausragt - bei 1.0 fiele die Unterlänge von „g" weg. */ ?>
            <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text truncate text-4xl font-semibold leading-tight tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
            <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="page-title-input w-full min-w-0 text-4xl font-semibold tracking-tight sm:text-5xl">
            <p class="mt-2 text-sm" style="color: var(--color-text-muted);" x-text="entryCountLabel()"></p>
            <?php /* Die Knöpfe stehen in der Standortzeile statt in einer eigenen
                     Spalte daneben - so bleibt der Überschrift die volle Breite.
                     Wird es eng, schrumpfen die Knöpfe auf ihr Symbol und der
                     Standort auf „…", statt dass die Zeile umbricht. */ ?>
            <div class="flex flex-nowrap items-center justify-between gap-x-3">
                <div class="min-w-0 flex-1"><?php include __DIR__ . '/partials/page_location.php'; ?></div>
                <div class="mt-2 flex shrink-0 items-center gap-2">
                    <button x-show="canEditPage" type="button" @click="openNewEntry" class="btn btn-primary" title="Eintrag anlegen" aria-label="Eintrag anlegen">
                        <span x-icon="plus"></span><span class="hidden sm:inline">Eintrag</span>
                    </button>
                    <?php /* Diktierter Eintrag: Der Server verteilt das Gesagte auf die
                             Spalten des Logbuchs (FR-LOG-08). Der Knopf startet nur -
                             Pause, Fortsetzen und Beenden steuert das Panel darunter. */ ?>
                    <?php if (!empty($voiceEnabled)): ?>
                        <button x-show="canEditPage && voiceSupported" x-cloak type="button" @click="toggleVoice" :disabled="isVoiceBusy()" class="btn btn-secondary" :class="voiceStatus === 'recording' ? 'is-recording' : ''" title="Eintrag diktieren" aria-label="Eintrag diktieren">
                            <span x-show="voiceStatus !== 'recording'" x-icon="mic"></span>
                            <span x-show="voiceStatus === 'recording'" x-cloak x-icon="square"></span>
                            <span :class="voiceStatus === 'recording' ? '' : 'hidden sm:inline'" x-text="voiceStatus === 'recording' ? voiceTimeLabel() : 'Diktieren'"></span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php include __DIR__ . '/partials/shared_page_meta.php'; ?>
        </div>

        <?php if (!empty($voiceEnabled)): ?>
            <div class="mb-4"><?php include __DIR__ . '/partials/voice_panel.php'; ?></div>
        <?php endif; ?>

        <p x-show="error" x-cloak x-text="error" class="mb-4 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>

        <?php /* Die Tabelle scrollt für sich, damit die Seite selbst bei vielen
                 Spalten nicht seitlich wandert. */ ?>
        <div class="log-table-wrap overflow-x-auto rounded-lg border" style="border-color: var(--color-border);">
            <table class="log-table w-full text-sm">
                <thead>
                    <tr style="color: var(--color-text-muted);">
                        <th class="log-cell text-left">
                            <button type="button" @click="sortBy('occurred_at')" class="log-sort">
                                Zeitpunkt <span x-text="sortIndicator('occurred_at')"></span>
                            </button>
                        </th>
                        <template x-for="column in columns" :key="column.id">
                            <th class="log-cell text-left" :class="column.is_numeric ? 'text-right' : ''">
                                <button type="button" @click="sortBy(column.id)" class="log-sort">
                                    <span x-text="column.name"></span> <span x-text="sortIndicator(column.id)"></span>
                                </button>
                            </th>
                        </template>
                        <th class="log-cell w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="entry in entries" :key="entry.id">
                        <tr class="log-row" @click="openEntry(entry)">
                            <td class="log-cell whitespace-nowrap font-medium" x-text="entryTimeLabel(entry)"></td>
                            <template x-for="column in columns" :key="column.id">
                                <td class="log-cell align-top" :class="column.is_numeric ? 'text-right tabular-nums whitespace-nowrap' : ''">
                                    <a x-show="hasCellMapUrl(entry, column)" :href="cellMapUrl(entry, column)" :title="cellTitle(entry, column)" target="_blank" rel="noopener" @click.stop class="inline-flex items-center gap-1 hover:underline">
                                        <span x-icon="map-pin"></span><span x-text="cellLabel(entry, column)"></span>
                                    </a>
                                    <span x-show="!hasCellMapUrl(entry, column)" x-text="cellLabel(entry, column)"></span>
                                </td>
                            </template>
                            <td class="log-cell text-right">
                                <span x-show="canEditPage" style="color: var(--color-text-muted);" x-icon="pencil"></span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!loading && entries.length === 0">
                        <td :colspan="columnSpan()" class="log-cell py-10 text-center" style="color: var(--color-text-muted);">
                            Noch keine Einträge.
                        </td>
                    </tr>
                    <tr x-show="loading">
                        <td :colspan="columnSpan()" class="log-cell py-10 text-center" style="color: var(--color-text-muted);">Lädt…</td>
                    </tr>
                </tbody>
                <?php /* Summen der Zahlenspalten über die geladenen Einträge. */ ?>
                <tfoot x-show="hasTotals() && entries.length > 0" x-cloak>
                    <tr style="color: var(--color-text-muted);">
                        <td class="log-cell font-medium">Summe</td>
                        <template x-for="column in columns" :key="column.id">
                            <td class="log-cell font-medium" :class="column.is_numeric ? 'text-right tabular-nums whitespace-nowrap' : ''" x-text="columnTotal(column)"></td>
                        </template>
                        <td class="log-cell"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php include __DIR__ . '/partials/page_location_dialog.php'; ?>

    <?php /* Eintrag anlegen oder ändern. Datum und Uhrzeit sind frei änderbar
             (FR-LOG-09). */ ?>
    <?php /* Escape gilt der obersten Ebene: Liegt die Standortauswahl darüber,
             schließt sie sich zuerst - sonst verschwänden beide auf einmal. */ ?>
    <div x-show="entryDialogOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-5" style="background-color: rgb(0 0 0 / 0.45);" @click.self="closeEntryDialog" @keydown.escape.window="locationDialogOpen || closeEntryDialog()" role="dialog" aria-modal="true" aria-labelledby="log-entry-title">
        <form @submit.prevent="saveEntry" class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-6 py-4" style="border-color: var(--color-border);">
                <h2 id="log-entry-title" class="text-xl font-semibold" x-text="editingEntryId ? 'Eintrag bearbeiten' : 'Neuer Eintrag'"></h2>
                <button type="button" @click="closeEntryDialog" :disabled="entryBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <label for="log-entry-time" class="block text-sm font-medium">Datum und Uhrzeit</label>
                <input id="log-entry-time" x-model="entryTime" :disabled="entryBusy" type="datetime-local" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">

                <template x-for="column in columns" :key="column.id">
                    <div class="mt-5">
                        <label class="block text-sm font-medium" x-text="column.name"></label>
                        <p x-show="column.type === 'user'" class="mt-2 text-sm" style="color: var(--color-text-muted);">Wird beim Anlegen automatisch eingetragen.</p>
                        <?php /* Bewertung: anklickbare Sterne statt Eingabefeld. Ein
                                 erneuter Klick auf den gesetzten Stern zählt zurück. */ ?>
                        <div x-show="isRatingColumn(column)" x-cloak class="mt-2 flex items-center gap-2">
                            <div class="flex items-center gap-1" role="group" :aria-label="column.name">
                                <template x-for="star in ratingChoices()" :key="star">
                                    <button type="button" @click="setRating(column, star)" :disabled="entryBusy" class="log-rating-star" :class="isRatingFilled(column, star) ? 'is-filled' : ''" :aria-pressed="isRatingFilled(column, star)" :aria-label="star + ' von 5 Sternen'" x-text="isRatingFilled(column, star) ? '★' : '☆'"></button>
                                </template>
                            </div>
                            <span class="text-xs" style="color: var(--color-text-muted);" x-text="ratingLabel(column)"></span>
                            <button type="button" x-show="ratingOf(column) !== null" x-cloak @click="clearRating(column)" :disabled="entryBusy" class="icon-action ml-auto shrink-0 p-1" title="Bewertung entfernen" aria-label="Bewertung entfernen" x-icon="x"></button>
                        </div>
                        <div x-show="column.type !== 'user' && !isRatingColumn(column)" class="mt-2 flex gap-2">
                            <input
                                :value="valueInput(column)"
                                @input="onValueInput(column, $event)"
                                :type="inputType(column)"
                                :step="inputStep(column)"
                                :placeholder="inputPlaceholder(column)"
                                :disabled="entryBusy"
                                class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm"
                                style="border-color: var(--color-border); background: var(--color-bg);"
                            >
                            <button x-show="isLocationColumn(column)" x-cloak type="button" @click="useCurrentLocationFor(column)" :disabled="entryBusy || isLocating(column)" class="btn btn-quiet shrink-0" title="Aktuellen Standort einsetzen" aria-label="Aktuellen Standort einsetzen">
                                <span x-icon="map-pin"></span>
                            </button>
                            <?php /* Dieselbe Auswahl wie beim Aufnahmeort der Seite:
                                     Adresssuche, Karte und aktueller Standort. */ ?>
                            <button x-show="isLocationColumn(column)" x-cloak type="button" @click="openLocationPickerForColumn(column)" :disabled="entryBusy" class="btn btn-quiet shrink-0" title="Auf der Karte wählen" aria-label="Auf der Karte wählen">
                                <span x-icon="map"></span>
                            </button>
                        </div>
                    </div>
                </template>

                <p x-show="entryError" x-cloak x-text="entryError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t px-6 py-4" style="border-color: var(--color-border);">
                <button x-show="editingEntryId" x-cloak type="button" @click="deleteEntry" :disabled="entryBusy" class="btn btn-quiet mr-auto" style="color: var(--color-danger);">Löschen</button>
                <button type="button" @click="closeEntryDialog" :disabled="entryBusy" class="btn btn-quiet">Abbrechen</button>
                <button type="submit" :disabled="entryBusy" class="btn btn-primary" x-text="entryBusy ? 'Speichert…' : 'Speichern'"></button>
            </div>
        </form>
    </div>

    <?php /* Spalten des Logbuchs: anlegen, umbenennen, verschieben, löschen
             (FR-LOG-03). Die Zeitspalte gehört fest dazu und fehlt hier. */ ?>
    <div x-show="columnDialogOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-5" style="background-color: rgb(0 0 0 / 0.45);" @click.self="closeColumnDialog" @keydown.escape.window="closeColumnDialog" role="dialog" aria-modal="true" aria-labelledby="log-columns-title">
        <div class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-6 py-4" style="border-color: var(--color-border);">
                <div>
                    <h2 id="log-columns-title" class="text-xl font-semibold">Spalten</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Datum und Uhrzeit hat jeder Eintrag ohnehin.</p>
                </div>
                <button type="button" @click="closeColumnDialog" :disabled="columnBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <template x-for="column in columns" :key="column.id">
                    <div class="mb-2 flex items-center gap-2 rounded-lg border p-3" style="border-color: var(--color-border);">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium" x-text="column.name"></p>
                            <p class="text-xs" style="color: var(--color-text-muted);" x-text="typeLabel(column)"></p>
                        </div>
                        <button type="button" @click="moveColumn(column, 'up')" :disabled="columnBusy" class="icon-action shrink-0" aria-label="Nach links" title="Nach links">↑</button>
                        <button type="button" @click="moveColumn(column, 'down')" :disabled="columnBusy" class="icon-action shrink-0" aria-label="Nach rechts" title="Nach rechts">↓</button>
                        <button type="button" @click="renameColumn(column)" :disabled="columnBusy" class="icon-action shrink-0" aria-label="Umbenennen" title="Umbenennen" x-icon="pencil"></button>
                        <button type="button" @click="removeColumn(column)" :disabled="columnBusy" class="icon-action icon-action-danger shrink-0" aria-label="Spalte löschen" title="Spalte löschen" x-icon="trash"></button>
                    </div>
                </template>

                <form @submit.prevent="addColumn" class="mt-5 border-t pt-5" style="border-color: var(--color-border);">
                    <label for="log-column-name" class="block text-sm font-medium">Neue Spalte</label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <input id="log-column-name" x-model="newColumnName" :disabled="columnBusy" type="text" placeholder="Name" class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <select x-model="newColumnType" :disabled="columnBusy" class="rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                            <template x-for="type in types" :key="type.value">
                                <option :value="type.value" x-text="type.label"></option>
                            </template>
                        </select>
                        <button type="submit" :disabled="columnBusy" class="btn btn-primary">Hinzufügen</button>
                    </div>
                </form>

                <p x-show="columnError" x-cloak x-text="columnError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            </div>

            <div class="flex justify-end border-t px-6 py-4" style="border-color: var(--color-border);">
                <button type="button" @click="closeColumnDialog" class="btn btn-quiet">Schließen</button>
            </div>
        </div>
    </div>
</div>
