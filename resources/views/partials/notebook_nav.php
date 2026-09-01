<div class="flex min-h-0 flex-1 flex-col" @dragend.window="clearDropTarget">
    <?php /* min-h gleicht die Kopfzeile auf dieselbe Höhe wie die der
             Seiten-Sidebar aus - deren Inhalt ist ohne Logo-Bild niedriger,
             ohne feste Höhe läge die Trennlinie hier ein paar Pixel tiefer. */ ?>
    <div class="flex min-h-[3.75rem] items-center gap-2 border-b px-4 pb-3 pt-5" style="border-color: var(--color-border);">
        <a href="/app" @click.prevent="navigateHome()" class="flex min-w-0 flex-1 items-center gap-2 font-semibold">
            <img src="/icon/logo-mark.svg" alt="" width="28" height="28" class="size-7 shrink-0">
            <span class="truncate text-base"><span class="font-bold" style="color: var(--color-danger);">nasmut</span>Notes</span>
        </a>
        <?php /* Mobil verlässt man die Notizbücher über „Home“ zur Übersicht
                 oder über ein Notizbuch zur Seitenauswahl - ein eigener
                 Schließen-Schalter hätte kein drittes Ziel. Zwischen `md` und
                 `xl` schließt er dagegen die überlagernde Schublade. */ ?>
        <button type="button" @click="showPages()" class="icon-action hidden md:inline-flex xl:hidden" aria-label="Notizbücher schließen" x-icon="x"></button>
    </div>
    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3" aria-label="Notizbücher">
        <button type="button" @click="selectCollection('favorites')" class="notebook-item" :class="activeCollection === 'favorites' ? 'is-active' : ''" :aria-current="activeCollection === 'favorites' ? 'page' : null"><span x-icon="star"></span>Favoriten</button>
        <button type="button" @click="selectCollection('all')" class="notebook-item" :class="activeCollection === 'all' ? 'is-active' : ''" :aria-current="activeCollection === 'all' ? 'page' : null"><span x-icon="layers"></span>Alle Notizen</button>
        <button type="button" @click="selectCollection('unassigned')" @dragenter.prevent="setDropTargetNotebook(null)" @dragover.prevent="setDropTargetNotebook(null)" @drop.prevent="dropPageOnNotebook(null, $event)" class="notebook-item" :class="{ 'is-active': activeCollection === 'unassigned', 'is-drop-target': isUnassignedDropTarget() }" :aria-current="activeCollection === 'unassigned' ? 'page' : null"><span x-icon="inbox"></span><span>Nicht zugewiesen</span><span x-show="isUnassignedDropTarget()" x-cloak class="ml-auto text-xs font-semibold">Hier ablegen</span></button>
        <button type="button" @click="selectCollection('shared')" class="notebook-item" :class="activeCollection === 'shared' ? 'is-active' : ''" :aria-current="activeCollection === 'shared' ? 'page' : null"><span x-icon="share-2"></span>Geteilt</button>
        <div class="mt-5 flex items-center justify-between px-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--color-text-muted);">
            <span>Notizbücher</span>
            <button type="button" @click="openNotebookDialog" class="icon-action -mr-1" aria-label="Notizbuch anlegen" title="Notizbuch anlegen" x-icon="plus"></button>
        </div>
        <template x-for="notebook in visibleNotebooks()" :key="notebook.id">
            <?php include __DIR__ . '/notebook_item.php'; ?>
        </template>

        <?php /* Ausgeblendete Notizbücher stehen abgetrennt darunter und klappen
                 erst auf Klick auf - sie sollen die Liste nicht wieder füllen. */ ?>
        <div x-show="hasHiddenNotebooks()" x-cloak class="mt-4 border-t pt-2" style="border-color: var(--color-border);">
            <?php /* Die Größe steht als Inline-Stil, damit sie nicht an einer erst
                     zu erzeugenden Tailwind-Klasse hängt. */ ?>
            <button type="button" @click="toggleHiddenNotebooks" class="hidden-notebooks-toggle flex w-full items-center gap-1 rounded-md px-2 py-0.5 leading-none hover:bg-black/5 dark:hover:bg-white/10" style="color: var(--color-text-muted); font-size: 12px;" :aria-expanded="hiddenNotebooksOpen">
                <span x-show="!hiddenNotebooksOpen" class="flex" x-icon="chevron-right"></span>
                <span x-show="hiddenNotebooksOpen" x-cloak class="flex" x-icon="chevron-down"></span>
                <span x-text="hiddenNotebooksLabel()"></span>
            </button>
            <?php /* Gedämpft, damit die eingeblendeten Notizbücher weiter die
                     Liste tragen und diese hier als Nebengleis lesbar bleiben. */ ?>
            <div x-show="hiddenNotebooksOpen" x-cloak class="mt-1 space-y-1 opacity-60 transition-opacity hover:opacity-100">
                <template x-for="notebook in hiddenNotebooks()" :key="notebook.id">
                    <?php include __DIR__ . '/notebook_item.php'; ?>
                </template>
            </div>
        </div>
    </nav>
    <div class="p-3">
        <button type="button" @click="selectCollection('trash')" @dragenter.prevent="setTrashDropTarget" @dragover.prevent="setTrashDropTarget" @drop.prevent="dropPagesOnTrash($event)" class="notebook-item" :class="{ 'is-active': activeCollection === 'trash', 'is-drop-target': isTrashDropTarget() }" :aria-current="activeCollection === 'trash' ? 'page' : null"><span x-icon="trash"></span><span>Papierkorb</span><span x-show="isTrashDropTarget()" x-cloak class="ml-auto text-xs font-semibold">Hier ablegen</span></button>
        <?php include __DIR__ . '/invite_panel.php'; ?>
        <div class="mt-2" x-data="offlineSettings" @import-dialog.window="trackImportDialog">
            <?php /* Der Prefetch läuft im Hintergrund weiter, auch wenn der Dialog
                     zu ist - ohne diese Zeile bliebe er unsichtbar. Ein Klick
                     führt direkt in den Sync-Bereich der Einstellungen. */ ?>
            <button type="button" @click="openSyncSettings" class="offline-status" :class="statusTone()" :title="statusText()">
                <span class="offline-status-dot"></span>
                <span class="min-w-0 flex-1 truncate" x-text="statusText()"></span>
            </button>
            <div class="mt-2 flex items-center gap-2">
                <button type="button" @click="openDialog" class="icon-action" aria-label="Offline und Einstellungen" title="Offline und Einstellungen" x-icon="settings"></button>
                <button type="button" @click="openInfoDialog" class="icon-action" aria-label="Datenschutz und Hinweise" title="Datenschutz und Hinweise" x-icon="info"></button>
                <?php if (!empty($isAdmin)): ?><a href="/admin" class="icon-action" aria-label="Administration" x-icon="shield"></a><?php endif; ?>
                <button type="button" @click="logout" class="icon-action ml-auto" aria-label="Abmelden" x-icon="log-out"></button>
            </div>
            <?php /* x-teleport: Diese Schublade fährt per CSS `translate` ein/aus,
                     was sie zum Containing Block für `position: fixed` macht -
                     ohne Teleport nach <body> säße der Dialog links in ihrer
                     Breite fest statt zentriert über dem ganzen Bildschirm. */ ?>
            <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-5" style="background-color: rgb(0 0 0 / 0.4);" @click.self="closeDialog" @keydown.escape.window="closeDialog">
                <div class="settings-dialog flex w-full max-w-4xl flex-col overflow-hidden rounded-xl border" role="dialog" aria-modal="true" aria-labelledby="settings-dialog-title" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
                    <header class="flex items-center justify-between gap-3 border-b px-6 py-4" style="border-color: var(--color-border);">
                        <div><h2 id="settings-dialog-title" class="font-semibold">Einstellungen</h2><p class="text-sm" style="color: var(--color-text-muted);" x-text="statusOnline ? 'Online' : 'Offline'"></p></div>
                        <button type="button" @click="closeDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
                    </header>
                    <div class="flex min-h-0 flex-1 flex-col overflow-hidden sm:grid sm:grid-cols-[13rem_minmax(0,1fr)]">
                        <?php /* Unter `sm` ersetzt ein Dropdown die Bereichsleiste - eine
                                 9rem breite Spalte daneben ließe für den Inhalt kaum noch
                                 Platz. */ ?>
                        <div class="border-b p-3 sm:hidden" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <label class="sr-only" for="settings-section-select">Bereich</label>
                            <select id="settings-section-select" x-model="settingsSection" class="w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                                <option value="app">App</option>
                                <option value="clients">Verbundene Clients</option>
                                <?php if (!empty($voiceEnabled)): ?><option value="speech2text">Speech2Text</option><?php endif; ?>
                                <option value="sync">Sync</option>
                                <option value="transfer">Import / Export</option>
                                <option value="storage">Speicher</option>
                            </select>
                        </div>
                        <nav class="hidden space-y-1 border-r p-3 sm:block" aria-label="Einstellungen" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <button type="button" @click="selectSettingsSection('app')" class="settings-nav-button" :class="isSettingsSection('app') ? 'is-active' : ''"><span x-icon="home"></span><span>App</span></button>
                            <button type="button" @click="selectSettingsSection('clients')" class="settings-nav-button" :class="isSettingsSection('clients') ? 'is-active' : ''"><span x-icon="laptop"></span><span>Verbundene Clients</span></button>
                            <?php if (!empty($voiceEnabled)): ?><button type="button" @click="selectSettingsSection('speech2text')" class="settings-nav-button" :class="isSettingsSection('speech2text') ? 'is-active' : ''"><span x-icon="mic"></span><span>Speech2Text</span></button><?php endif; ?>
                            <button type="button" @click="selectSettingsSection('sync')" class="settings-nav-button" :class="isSettingsSection('sync') ? 'is-active' : ''"><span x-icon="wifi"></span><span>Sync</span></button>
                            <button type="button" @click="selectSettingsSection('transfer')" class="settings-nav-button" :class="isSettingsSection('transfer') ? 'is-active' : ''"><span x-icon="upload"></span><span>Import / Export</span></button>
                            <button type="button" @click="selectSettingsSection('storage')" class="settings-nav-button" :class="isSettingsSection('storage') ? 'is-active' : ''"><span x-icon="folder"></span><span>Speicher</span></button>
                        </nav>
                        <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-6">
                            <section x-show="isSettingsSection('app')">
                                <h3 class="text-xl font-semibold">App installieren</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">nasmutNotes als eigenständige App auf diesem Gerät verwenden.</p>
                                <div class="mt-5 rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                            <div class="flex items-center gap-3">
                                <img src="/icon/icon-192.png" alt="" class="size-11 rounded-xl">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">Als App installieren</p>
                                    <p x-show="appInstalled" x-cloak class="text-xs" style="color: var(--color-text-muted);">Bereits auf dem Home-Bildschirm installiert.</p>
                                    <p x-show="showIosInstallHint" x-cloak class="text-xs" style="color: var(--color-text-muted);">In Safari: Teilen → „Zum Home-Bildschirm“.</p>
                                    <p x-show="installMessage" x-cloak class="text-xs" style="color: var(--color-text-muted);" x-text="installMessage"></p>
                                </div>
                            </div>
                            <button x-show="canInstallApp" x-cloak type="button" class="btn btn-primary mt-3 w-full" @click="installApp">App installieren</button>
                        </div>
                                <?php /* Aufnahmeort neuer Notizen: je Gerät, in der Vorgabe
                                         erst auf Klick (FR-NOTE-25). */ ?>
                                <h3 class="mt-8 text-xl font-semibold">Standort</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Notizen können festhalten, wo sie entstanden sind. Die Einstellung wird in deinem Benutzerprofil gespeichert.</p>
                                <div class="mt-4 space-y-3">
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                                        <input type="radio" :name="locationRadioName" value="manual" :checked="isLocationMode('manual')" :disabled="locationModeSaving" @change="selectLocationMode('manual')" class="mt-1">
                                        <span class="min-w-0">
                                            <span class="block font-medium">Nur auf Klick</span>
                                            <span class="block text-xs" style="color: var(--color-text-muted);">Neue Notizen entstehen ohne Standort; auf der Notiz gibt es „Standort hinzufügen".</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                                        <input type="radio" :name="locationRadioName" value="auto" :checked="isLocationMode('auto')" :disabled="!locationSupported || locationModeSaving" @change="selectLocationMode('auto')" class="mt-1">
                                        <span class="min-w-0">
                                            <span class="block font-medium">Immer automatisch</span>
                                            <span class="block text-xs" style="color: var(--color-text-muted);">Jede neue Notiz - auch die per Sprache - hält den Standort gleich beim Anlegen fest. Ohne Ortung entsteht sie einfach ohne.</span>
                                        </span>
                                    </label>
                                    <p x-show="!locationSupported" x-cloak class="text-xs" style="color: var(--color-danger);">Dieser Browser bietet keine Ortung an.</p>
                                </div>
                            </section>

                            <?php /* Verbundene Clients: Desktop-Assistant (per Paarung)
                                     und Automations-Token in einer Liste, dazu der
                                     eigene KI-Verbrauch. */ ?>
                            <section x-show="isSettingsSection('clients')" x-cloak>
                                <h3 class="text-xl font-semibold">Verbundene Clients</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Geräte und Apps, die auf dein Konto zugreifen. Der Desktop-Assistent verbindet sich über einen Code, den er im Browser dieser App öffnet.</p>

                                <div class="mt-5">
                                    <h4 class="text-sm font-semibold">KI-Verbrauch</h4>
                                    <p x-show="aiUsageError" x-cloak x-text="aiUsageError" class="mt-2 text-sm" style="color: var(--color-danger);" role="alert"></p>
                                    <div x-show="aiUsage !== null && !aiUsageError" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div class="rounded-lg border p-3" style="border-color: var(--color-border);">
                                            <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--color-text-muted);">Letzte 30 Tage</p>
                                            <p class="mt-1 text-base font-semibold" x-text="aiUsageTokens(aiUsage ? aiUsage.last_30_days : null)"></p>
                                            <p class="mt-0.5 text-sm" style="color: var(--color-text-muted);" x-text="aiUsageCost(aiUsage ? aiUsage.last_30_days : null)"></p>
                                        </div>
                                        <div class="rounded-lg border p-3" style="border-color: var(--color-border);">
                                            <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--color-text-muted);">Gesamt</p>
                                            <p class="mt-1 text-base font-semibold" x-text="aiUsageTokens(aiUsage ? aiUsage.total : null)"></p>
                                            <p class="mt-0.5 text-sm" style="color: var(--color-text-muted);" x-text="aiUsageCost(aiUsage ? aiUsage.total : null)"></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-8 border-t pt-5" style="border-color: var(--color-border);">
                                    <h4 class="text-sm font-semibold">Desktop-Assistent verbinden</h4>
                                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Hat der Client seinen Browser-Link verloren, kannst du den Code hier auch von Hand bestätigen.</p>
                                    <form @submit.prevent="approvePairCode" class="mt-3 flex flex-col gap-2 sm:flex-row">
                                        <label class="sr-only" for="pair-code-input">Verbindungsgcode</label>
                                        <input id="pair-code-input" type="text" x-model="pairCodeInput" placeholder="z. B. K7M2-Q4XP" :disabled="pairApproving" class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm font-mono uppercase" style="border-color: var(--color-border); background: var(--color-bg);">
                                        <button type="submit" class="btn btn-primary shrink-0" :disabled="pairApproving" x-text="pairApproving ? 'Prüfe…' : 'Verbinden'"></button>
                                    </form>
                                    <p x-show="pairCodeMessage" x-cloak x-text="pairCodeMessage" class="mt-2 text-sm" style="color: var(--color-success);" role="status"></p>
                                    <p x-show="pairCodeError" x-cloak x-text="pairCodeError" class="mt-2 text-sm" style="color: var(--color-danger);" role="alert"></p>
                                </div>

                                <div class="mt-8 border-t pt-5" style="border-color: var(--color-border);">
                                    <h4 class="text-sm font-semibold">Geräte</h4>
                                    <p x-show="deviceTokensLoading" class="mt-3 text-sm" style="color: var(--color-text-muted);">Lädt…</p>
                                    <p x-show="!deviceTokensLoading && deviceTokens.length === 0" class="mt-3 text-sm" style="color: var(--color-text-muted);">Noch keine verbundenen Geräte.</p>
                                    <ul class="mt-3 space-y-2">
                                        <template x-for="token in deviceTokens" :key="token.id">
                                            <li class="flex items-center gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2">
                                                        <p class="truncate text-sm font-medium" x-text="token.label"></p>
                                                        <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" style="background: var(--color-bg-subtle); color: var(--color-text-muted);" x-text="token.source === 'desktop' ? 'Desktop' : 'Automation'"></span>
                                                    </div>
                                                    <p class="mt-0.5 truncate text-xs" style="color: var(--color-text-muted);" x-text="deviceTokenSummary(token)"></p>
                                                </div>
                                                <button type="button" @click="revokeDeviceToken(token)" class="shrink-0 text-sm font-medium" style="color: var(--color-danger);" x-text="token.source === 'desktop' ? 'Trennen' : 'Widerrufen'"></button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </section>

                            <?php if (!empty($voiceEnabled)): ?>
                            <section x-show="isSettingsSection('speech2text')" x-cloak>
                                <h3 class="text-xl font-semibold">Speech2Text</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">NotesVoice: Diktat per Doppeltipp auf die Geräterückseite, direkt in die Zwischenablage - ohne diese App zu öffnen.</p>

                                <div class="mt-5 border-t pt-5" style="border-color: var(--color-border);">
                                    <button type="button" @click="toggleSpeechAutomation" class="flex w-full items-center gap-1.5 rounded-md py-1 leading-none hover:bg-black/5 dark:hover:bg-white/10" :aria-expanded="speechAutomationOpen">
                                        <span x-show="!speechAutomationOpen" class="flex" x-icon="chevron-right"></span>
                                        <span x-show="speechAutomationOpen" x-cloak class="flex" x-icon="chevron-down"></span>
                                        <span class="text-sm font-semibold">iPhone Speech Automation</span>
                                    </button>

                                    <div x-show="speechAutomationOpen" x-cloak class="mt-3 space-y-5">
                                        <div>
                                            <h4 class="text-sm font-semibold">Automations-Token</h4>
                                            <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Erlaubt einer iOS-Automation, in deinem Namen zu diktieren - sonst nichts. Die Liste aller Geräte steht im Bereich „Verbundene Clients".</p>

                                            <form @submit.prevent="createDeviceToken" class="mt-3 flex flex-col gap-2 sm:flex-row">
                                                <label class="sr-only" for="device-token-label">Name des Geräts</label>
                                                <input id="device-token-label" type="text" maxlength="60" x-model="deviceTokenLabel" placeholder="z. B. iPhone von Steffen" :disabled="deviceTokenCreating" class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                                                <button type="submit" class="btn btn-primary shrink-0" :disabled="deviceTokenCreating" x-text="deviceTokenCreating ? 'Erstelle…' : 'Token erzeugen'"></button>
                                            </form>

                                            <div x-show="deviceTokenLastCreated" x-cloak class="mt-3 rounded-lg p-3" style="background: var(--color-bg-subtle);">
                                                <p class="text-sm font-medium">Token für „<span x-text="deviceTokenLastCreated ? deviceTokenLastCreated.label : ''"></span>“ (nur jetzt sichtbar)</p>
                                                <p class="mt-1.5 break-all font-mono text-xs" style="color: var(--color-text-muted);" x-text="deviceTokenLastCreated ? deviceTokenLastCreated.token : ''"></p>
                                                <p class="mt-2 text-xs" style="color: var(--color-text-muted);">In den Kurzbefehl einfügen (Anleitung unten) - hier verlässt der Token die Seite und ist danach nicht mehr abrufbar.</p>
                                                <button type="button" @click="copyDeviceToken" class="btn btn-secondary mt-3 w-full" x-text="deviceTokenCopyLabel"></button>
                                            </div>

                                            <p x-show="deviceTokenError" x-cloak x-text="deviceTokenError" class="mt-3 text-sm" style="color: var(--color-danger);" role="alert"></p>
                                        </div>

                                        <div class="border-t pt-5" style="border-color: var(--color-border);">
                                            <h4 class="text-sm font-semibold">Einrichtung auf dem iPhone</h4>
                                            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm">
                                                <li>Oben einen Token erzeugen und kopieren (Schritt bleibt geöffnet, bis du ihn kopiert hast).</li>
                                                <li>App „Kurzbefehle" → neuer Kurzbefehl „NotesVoice" mit den Aktionen:
                                                    <ol class="mt-1.5 list-[lower-alpha] space-y-1 pl-5" style="color: var(--color-text-muted);">
                                                        <li>„Audio aufnehmen"</li>
                                                        <li>„Inhalte von URL abrufen" - Methode POST, Adresse <code class="rounded px-1 text-xs" style="background: var(--color-bg-subtle);">/api/voice/quick</code> dieser App, Kopfzeile <code class="rounded px-1 text-xs" style="background: var(--color-bg-subtle);">Authorization: Bearer &lt;Token&gt;</code>, Anfragetext „Formular" - dort ein Feld hinzufügen, Typ „Datei", Schlüssel <code class="rounded px-1 text-xs" style="background: var(--color-bg-subtle);">audio</code>, Wert die Aufnahme aus Schritt 1 (nicht „Anfragetext: Datei" - das schickt die Aufnahme ohne Feldnamen)</li>
                                                        <li>„Wörterbuchwert abrufen" - Wörterbuch: die Antwort aus Schritt 2 (Inhalte von URL), Schlüssel <code class="rounded px-1 text-xs" style="background: var(--color-bg-subtle);">text</code></li>
                                                        <li>„In Zwischenablage kopieren"</li>
                                                    </ol>
                                                </li>
                                                <li>Einstellungen → Bedienungshilfen → Berühren → Rückseitentipp → „Doppeltippen" → Kurzbefehl „NotesVoice" auswählen.</li>
                                                <li>Doppeltipp auf die Rückseite, sprechen, im Aufnahme-Overlay auf „Fertig" tippen - der aufbereitete Text liegt danach in der Zwischenablage, ohne dass diese App geöffnet wurde.</li>
                                            </ol>
                                            <p class="mt-3 text-xs" style="color: var(--color-text-muted);">Der Token steht im Klartext im Kurzbefehl. Bei Verlust des Geräts oder Verdacht auf Missbrauch oben einfach widerrufen - das genügt, ein neuer Token braucht nur die Schritte 1 und 2b erneut.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 border-t pt-5" style="border-color: var(--color-border);">
                                    <button type="button" @click="toggleTemplates" class="flex w-full items-center gap-1.5 rounded-md py-1 leading-none hover:bg-black/5 dark:hover:bg-white/10" :aria-expanded="templatesOpen">
                                        <span x-show="!templatesOpen" class="flex" x-icon="chevron-right"></span>
                                        <span x-show="templatesOpen" x-cloak class="flex" x-icon="chevron-down"></span>
                                        <span class="text-sm font-semibold">Vorlagen</span>
                                    </button>

                                    <div x-show="templatesOpen" x-cloak class="mt-3 space-y-5">
                                        <p class="text-xs" style="color: var(--color-text-muted);">Vor jeder Diktat-Aufnahme für eine Notiz wählst du eine Vorlage: eine Anweisung, wie das Diktat aufbereitet werden soll (z. B. als Angebot mit Position, Menge und Preis), optional mit eigenem Vokabular für Fachbegriffe.</p>

                                        <div x-show="voiceTemplates.length > 0" x-cloak class="space-y-1">
                                            <template x-for="template in voiceTemplates" :key="template.id">
                                                <div class="rounded-lg" style="background: var(--color-bg-subtle);">
                                                    <?php /* Zusammengeklappt steht nur der Name da - die Liste soll
                                                             im Dialog nicht seitenlang werden. */ ?>
                                                    <div class="flex items-center gap-2 px-3 py-2">
                                                        <button type="button" @click="toggleTemplateDetails(template)" :aria-expanded="isTemplateExpanded(template)" class="flex min-w-0 flex-1 items-center gap-1.5 text-left">
                                                            <span x-show="!isTemplateExpanded(template)" class="flex shrink-0" x-icon="chevron-right"></span>
                                                            <span x-show="isTemplateExpanded(template)" x-cloak class="flex shrink-0" x-icon="chevron-down"></span>
                                                            <span class="truncate text-sm font-medium" x-text="template.name"></span>
                                                        </button>
                                                        <span class="flex shrink-0 gap-2">
                                                            <button type="button" @click="startEditVoiceTemplate(template)" :disabled="voiceTemplateSaving" class="text-xs font-medium" style="color: var(--color-text-muted);">Bearbeiten</button>
                                                            <button type="button" @click="deleteVoiceTemplate(template)" :disabled="voiceTemplateSaving" class="text-xs font-medium" style="color: var(--color-danger);">Löschen</button>
                                                        </span>
                                                    </div>
                                                    <div x-show="isTemplateExpanded(template)" x-cloak class="px-3 pb-3">
                                                        <p class="whitespace-pre-line text-xs" style="color: var(--color-text-muted);" x-text="template.instruction"></p>
                                                        <p x-show="template.vocabulary" x-cloak class="mt-2 text-xs" style="color: var(--color-text-muted);"><span class="font-medium">Vokabular:</span> <span x-text="template.vocabulary"></span></p>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        <p x-show="voiceTemplates.length === 0 && !voiceTemplatesLoading" x-cloak class="text-sm" style="color: var(--color-text-muted);">Noch keine eigene Vorlage angelegt.</p>

                                        <form @submit.prevent="saveVoiceTemplate" class="space-y-2 rounded-lg border p-3" style="border-color: var(--color-border);">
                                            <p class="text-xs font-semibold" x-text="editingVoiceTemplateId ? 'Vorlage bearbeiten' : 'Neue Vorlage'"></p>
                                            <div>
                                                <label class="sr-only" :for="templateFieldIds.name">Name der Vorlage</label>
                                                <input :id="templateFieldIds.name" type="text" maxlength="80" x-model="voiceTemplateName" placeholder="z. B. Angebot mit Positionen" :disabled="voiceTemplateSaving" class="w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                                            </div>
                                            <div>
                                                <label class="sr-only" :for="templateFieldIds.instruction">Anweisung</label>
                                                <textarea :id="templateFieldIds.instruction" x-model="voiceTemplateInstruction" placeholder="Wie soll das Diktat aufbereitet werden?" rows="4" :disabled="voiceTemplateSaving" class="w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                                            </div>
                                            <div>
                                                <label class="sr-only" :for="templateFieldIds.vocabulary">Vokabular (optional)</label>
                                                <textarea :id="templateFieldIds.vocabulary" x-model="voiceTemplateVocabulary" placeholder="Fachbegriffe, max. 600 Zeichen (optional)" rows="2" :disabled="voiceTemplateSaving" class="w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <button x-show="editingVoiceTemplateId" x-cloak type="button" @click="cancelEditVoiceTemplate" class="btn btn-quiet" :disabled="voiceTemplateSaving">Abbrechen</button>
                                                <button type="submit" class="btn btn-primary" :disabled="voiceTemplateSaving" x-text="editingVoiceTemplateId ? 'Speichern' : 'Anlegen'"></button>
                                            </div>
                                        </form>

                                        <p x-show="voiceTemplateError" x-cloak x-text="voiceTemplateError" class="text-sm" style="color: var(--color-danger);" role="alert"></p>

                                        <div x-show="globalVoiceTemplates.length > 0" x-cloak class="border-t pt-4" style="border-color: var(--color-border);">
                                            <h4 class="text-sm font-semibold">Globale Vorlagen</h4>
                                            <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Vom Administrator angelegt. Schalte ab, was du nicht brauchst - abgeschaltete stehen nicht mehr in der Auswahl vor der Aufnahme.</p>
                                            <div class="mt-2 space-y-1">
                                                <template x-for="template in globalVoiceTemplates" :key="template.id">
                                                    <div class="rounded-lg" style="background: var(--color-bg-subtle);">
                                                        <div class="flex items-center gap-2 px-3 py-2">
                                                            <button type="button" @click="toggleTemplateDetails(template)" :aria-expanded="isTemplateExpanded(template)" class="flex min-w-0 flex-1 items-center gap-1.5 text-left">
                                                                <span x-show="!isTemplateExpanded(template)" class="flex shrink-0" x-icon="chevron-right"></span>
                                                                <span x-show="isTemplateExpanded(template)" x-cloak class="flex shrink-0" x-icon="chevron-down"></span>
                                                                <span class="truncate text-sm font-medium" x-text="template.name"></span>
                                                            </button>
                                                            <label class="flex shrink-0 items-center gap-1.5 text-xs" style="color: var(--color-text-muted);">
                                                                <input type="checkbox" :checked="template.active" :disabled="voiceTemplateSaving" @change="toggleGlobalTemplate(template)">
                                                                <span x-text="template.active ? 'Aktiv' : 'Aus'"></span>
                                                            </label>
                                                        </div>
                                                        <div x-show="isTemplateExpanded(template)" x-cloak class="px-3 pb-3">
                                                            <p class="whitespace-pre-line text-xs" style="color: var(--color-text-muted);" x-text="template.instruction"></p>
                                                            <p x-show="template.vocabulary" x-cloak class="mt-2 text-xs" style="color: var(--color-text-muted);"><span class="font-medium">Vokabular:</span> <span x-text="template.vocabulary"></span></p>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                            <?php endif; ?>

                            <section x-show="isSettingsSection('sync')" x-cloak>
                                <h3 class="text-xl font-semibold">Synchronisation</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Lokale Änderungen übertragen und Offline-Inhalte aktualisieren.</p>

                                <?php /* Konflikte stehen ganz oben, weil sie eine Entscheidung
                                         brauchen - der Klick auf den Sync-Status in der Leiste
                                         landet genau hier (siehe openSyncSettings). */ ?>
                                <?php /* Behoben wird ein Konflikt nur auf der Notiz selbst - dort stehen
                                         beide Fassungen im Kontext nebeneinander (roter Knopf in der
                                         Werkzeugleiste). Hier gibt es deshalb nur die Übersicht und den
                                         Einstieg dorthin. */ ?>
                                <div x-show="conflicts.length > 0" x-cloak class="mt-5 space-y-3">
                                    <h4 class="text-sm font-semibold" style="color: var(--color-danger);">Sync-Konflikte</h4>
                                    <template x-for="conflict in conflicts" :key="conflict.id">
                                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4" style="border-color: var(--color-danger);">
                                            <div class="min-w-0">
                                                <p class="font-medium" x-text="conflict.title"></p>
                                                <p class="mt-1 text-xs" style="color: var(--color-text-muted);" x-text="localConflictLabel(conflict)"></p>
                                                <p class="text-xs" style="color: var(--color-text-muted);" x-text="serverConflictLabel(conflict)"></p>
                                            </div>
                                            <button type="button" @click="openConflictNote(conflict)" class="btn btn-secondary shrink-0">Notiz öffnen</button>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="blocked.length > 0" x-cloak class="mt-5 space-y-3">
                                    <h4 class="text-sm font-semibold" style="color: var(--color-danger);">Blockierte Änderungen</h4>
                                    <template x-for="entry in blocked" :key="entry.id">
                                        <div class="rounded-lg border p-4" style="border-color: var(--color-danger);">
                                            <p class="font-medium" x-text="entry.title"></p>
                                            <p class="mt-1 text-sm" style="color: var(--color-text-muted);" x-text="entry.last_error"></p>
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <button type="button" @click="retryBlocked(entry)" :disabled="isResolvingBlocked(entry) || !statusOnline" class="btn btn-secondary">Erneut versuchen</button>
                                                <button type="button" @click="discardBlocked(entry)" :disabled="isResolvingBlocked(entry)" class="btn btn-quiet">Lokale Änderung verwerfen</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <h4 class="mt-5 text-sm font-semibold">Lokal synchronisiert</h4>
                                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Notizen</dt><dd class="mt-1 text-2xl font-semibold" x-text="localNoteCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Aufgabenlisten</dt><dd class="mt-1 text-2xl font-semibold" x-text="localTaskPageCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Bilder</dt><dd class="mt-1 text-2xl font-semibold" x-text="localImageCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Dateien</dt><dd class="mt-1 text-2xl font-semibold" x-text="localFileCount"></dd></div>
                                </dl>
                                <h4 class="mt-5 text-sm font-semibold">Übertragung</h4>
                                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Ausstehend</dt><dd class="mt-1 text-2xl font-semibold" x-text="pendingSync"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Konflikte</dt><dd class="mt-1 text-2xl font-semibold" x-text="conflictCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Blockiert</dt><dd class="mt-1 text-2xl font-semibold" x-text="blockedCount"></dd></div>
                                </dl>
                                <div class="mt-5 flex flex-wrap gap-2">
                                    <button type="button" @click="syncNow" :disabled="busy || !statusOnline" class="btn btn-primary">Jetzt synchronisieren</button>
                                    <button type="button" x-show="!statusPrefetching" @click="downloadNow" :disabled="busy || !statusOnline" class="btn btn-secondary">Offline-Inhalte aktualisieren</button>
                                    <button type="button" x-show="statusPrefetching" x-cloak @click="cancelDownload" class="btn btn-secondary" x-text="cancelLabel()"></button>
                                    <button type="button" @click="clearCache" :disabled="busy" class="btn btn-quiet">Lokalen Cache leeren</button>
                                </div>
                                <div class="mt-6 border-t pt-5" style="border-color: var(--color-border);">
                                    <label class="block text-sm font-medium" for="settings-cache-limit">Offline-Limit</label>
                                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Wie viele zuletzt geänderte Seiten lokal verfügbar sein sollen.</p>
                                    <div class="mt-2 flex gap-2"><select id="settings-cache-limit" x-model="limit" class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"><template x-for="option in limits" :key="option"><option :value="option" x-text="limitLabel(option)"></option></template></select><button type="button" @click="saveLimit" :disabled="busy" class="btn btn-secondary">Speichern</button></div>
                                </div>
                            </section>

                            <section x-show="isSettingsSection('transfer')" x-cloak>
                                <h3 class="text-xl font-semibold">Import / Export</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Notizen aus anderen Anwendungen übernehmen oder den Workspace sichern.</p>
                                <?php include __DIR__ . '/import_panel.php'; ?>
                                <?php include __DIR__ . '/export_panel.php'; ?>
                            </section>

                            <section x-show="isSettingsSection('storage')" x-cloak>
                                <h3 class="text-xl font-semibold">Speicher</h3>
                                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Inhalte im Workspace und lokal belegter Browser-Speicher.</p>
                                <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Notizbücher</dt><dd class="mt-1 text-2xl font-semibold" x-text="workspaceNotebookCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Seiten</dt><dd class="mt-1 text-2xl font-semibold" x-text="workspacePageCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Tasks</dt><dd class="mt-1 text-2xl font-semibold" x-text="workspaceTaskCount"></dd></div>
                                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);"><dt class="text-xs" style="color: var(--color-text-muted);">Dateien</dt><dd class="mt-1 text-2xl font-semibold" x-text="workspaceFileCount"></dd></div>
                                </dl>
                                <div class="mt-5 rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);"><p class="text-sm" style="color: var(--color-text-muted);">Online belegt</p><p class="mt-1 text-3xl font-semibold" x-text="workspaceStorageLabel"></p><p class="mt-1 text-xs" style="color: var(--color-text-muted);">Notizinhalte, Versionsstände, Bilder und Dateianhänge auf dem Server.</p></div>
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4" style="border-color: var(--color-border);">
                                    <div><p class="text-sm font-medium">Bilder optimieren</p><p class="mt-1 text-xs" style="color: var(--color-text-muted);">Alle eigenen Bilder mit 82 % Qualität auf maximal 1960 px Breite komprimieren.</p></div>
                                    <button type="button" @click="compressOwnImages" :disabled="busy || !statusOnline" class="btn btn-secondary">Bilder komprimieren</button>
                                </div>
                                <div class="mt-6">
                                    <h4 class="text-sm font-semibold">Größte Inhalte</h4>
                                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Top 10 nach Online-Speicherverbrauch.</p>
                                    <div class="mt-3 divide-y" style="border-color: var(--color-border);">
                                        <template x-for="item in workspaceTopItems" :key="item.id">
                                            <div class="flex items-center gap-3 py-3">
                                                <span x-show="item.type === 'note'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                                                <span x-show="item.type === 'task'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                                                <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium" x-text="item.title"></p><p class="mt-0.5 text-xs" style="color: var(--color-text-muted);"><span x-text="item.type === 'task' ? 'Aufgabenliste' : 'Notiz'"></span><span x-show="item.deleted_at" x-cloak> · Papierkorb</span></p></div>
                                                <span class="shrink-0 text-sm font-medium tabular-nums" x-text="item.sizeLabel"></span>
                                            </div>
                                        </template>
                                        <p x-show="workspaceTopItems.length === 0" class="py-4 text-sm" style="color: var(--color-text-muted);">Noch keine gespeicherten Inhalte.</p>
                                    </div>
                                </div>
                                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm"><div><dt style="color: var(--color-text-muted);">Lokal verfügbar</dt><dd class="font-medium" x-text="pageCount + ' Seiten'"></dd></div><div><dt style="color: var(--color-text-muted);">Browser-Speicher</dt><dd class="font-medium"><span x-text="usageLabel"></span> / <span x-text="quotaLabel"></span></dd></div></dl>
                            </section>

                            <p x-show="error" class="mt-5 text-sm" style="color: var(--color-danger);" x-text="error" role="alert"></p>
                            <p x-show="message" class="mt-5 text-sm" style="color: var(--color-success);" x-text="message"></p>
                        </div>
                    </div>
                </div>
            </div>
            </template>
        </div>
    </div>
</div>
