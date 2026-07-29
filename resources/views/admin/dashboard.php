<div class="mx-auto max-w-6xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminDashboard">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Nutzer &amp; Speicher</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/admin/invites" class="btn btn-secondary">Einladungen</a>
            <a href="/admin/backups" class="btn btn-secondary">Sicherungen</a>
            <a href="/app" class="btn btn-secondary">Zum Workspace</a>
        </div>
    </div>

    <p x-show="error" x-cloak x-text="error" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>
    <p x-show="message" x-cloak x-text="message" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-success) 14%, transparent);" role="status"></p>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Nutzer</p>
            <p class="mt-1 text-2xl font-semibold" x-text="totals.user_count || 0"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Seiten</p>
            <p class="mt-1 text-2xl font-semibold" x-text="totals.page_count || 0"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Bilder</p>
            <p class="mt-1 text-2xl font-semibold" x-text="totals.image_count || 0"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Belegter Speicher</p>
            <p class="mt-1 text-2xl font-semibold" x-text="formatBytes(totals.total_bytes)"></p>
        </div>
    </div>

    <div class="document-surface mb-8 flex flex-wrap items-center justify-between gap-4 p-5">
        <div>
            <h2 class="font-semibold">Standard-Speicherkontingent</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Gilt für alle Nutzer ohne eigenen Wert. Aktuell:
                <span class="font-medium" style="color: var(--color-text);" x-text="defaultQuota > 0 ? defaultQuota + ' MB' : 'unbegrenzt'"></span>
            </p>
        </div>
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="editDefaultQuota">Ändern</button>
    </div>

    <div class="document-surface mb-8 flex flex-wrap items-center justify-between gap-4 p-5">
        <div>
            <h2 class="font-semibold">Maximale Größe je Dateianhang</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Gilt für Anhänge an Notizseiten. Aktuell:
                <span class="font-medium" style="color: var(--color-text);" x-text="maxAttachmentMb + ' MB'"></span>
            </p>
        </div>
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="editMaxAttachment">Ändern</button>
    </div>

    <?php /* Offline-Limit: bis hierhin laden Clients Anhänge und Bilder automatisch
             mit, größere bleiben online-only (FR-OFFLINE-06). */ ?>
    <div class="document-surface mb-8 flex flex-wrap items-center justify-between gap-4 p-5">
        <div>
            <h2 class="font-semibold">Offline verfügbare Anhänge</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Anhänge und Bilder bis zu dieser Größe werden automatisch zur Notiz heruntergeladen.
                Größere brauchen zum Öffnen eine Internetverbindung. Aktuell:
                <span class="font-medium" style="color: var(--color-text);" x-text="offlineLimitLabel()"></span>
            </p>
        </div>
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="editOfflineAttachmentLimit">Ändern</button>
    </div>

    <?php /* Sprachnotizen: Freischaltung, Zugangsdaten, Modelle und die Anweisung,
             nach der das Transkript aufbereitet wird (FR-VOICE-05). */ ?>
    <div class="document-surface mb-8 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold">Sprachnotizen</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                    Diktate werden bei OpenAI transkribiert, aufbereitet und mit Überschrift und
                    Notizbuchvorschlag zurückgegeben. Aktuell:
                    <span class="font-medium" style="color: var(--color-text);" x-text="voiceStatusLabel()"></span>
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium">
                <input type="checkbox" x-model="voiceEnabled" :disabled="busy">
                Freigeschaltet
            </label>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <?php /* Der Schlüssel gehört zum Deployment und steht nur in der .env -
                     hier ist er deshalb reine Anzeige. */ ?>
            <div class="sm:col-span-2 rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                <span class="font-medium">API-Schlüssel:</span>
                <span x-text="voiceApiKeyLabel()"></span>
            </div>
            <div>
                <label for="voice-transcribe-model" class="block text-sm font-medium">Modell für die Transkription</label>
                <input id="voice-transcribe-model" x-model="voiceTranscribeModel" :disabled="busy" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="voice-language" class="block text-sm font-medium">Sprache</label>
                <input id="voice-language" x-model="voiceLanguage" :disabled="busy" type="text" maxlength="2" placeholder="de" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Zweistelliger Code; leer lässt das Modell die Sprache erkennen.</p>
            </div>
            <div>
                <label for="voice-postprocess-model" class="block text-sm font-medium">Modell für die Nachbearbeitung</label>
                <input id="voice-postprocess-model" x-model="voicePostprocessModel" :disabled="busy || !voicePostprocessEnabled" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 pb-2 text-sm font-medium">
                    <input type="checkbox" x-model="voicePostprocessEnabled" :disabled="busy">
                    Transkript nachbearbeiten
                </label>
            </div>
            <div>
                <label for="voice-max-seconds" class="block text-sm font-medium">Maximale Aufnahmedauer (Sekunden)</label>
                <input id="voice-max-seconds" x-model.number="voiceMaxSeconds" :disabled="busy" type="number" min="10" max="3600" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="voice-max-mb" class="block text-sm font-medium">Maximale Aufnahmegröße (MB)</label>
                <input id="voice-max-mb" x-model.number="voiceMaxMb" :disabled="busy" type="number" min="1" max="25" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="sm:col-span-2">
                <label for="voice-base-url" class="block text-sm font-medium">Adresse des Dienstes</label>
                <input id="voice-base-url" x-model="voiceBaseUrl" :disabled="busy" type="url" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Nur ändern, wenn ein kompatibler Dienst statt api.openai.com genutzt wird.</p>
            </div>
            <div class="sm:col-span-2">
                <label for="voice-prompt" class="block text-sm font-medium">Anweisung für die Nachbearbeitung</label>
                <textarea id="voice-prompt" x-model="voicePrompt" :disabled="busy || !voicePostprocessEnabled" rows="10" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Das Modell muss ein JSON-Objekt mit den Feldern <code>title</code>, <code>notebook</code> und <code>text</code> zurückgeben.</p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button type="button" class="btn btn-quiet" :disabled="busy" @click="resetVoicePrompt">Standardanweisung</button>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="saveVoiceSettings">Speichern</button>
        </div>
    </div>

    <?php /* Verwaiste Bilder: in keiner Notiz und in keiner Version mehr referenziert. */ ?>
    <div class="document-surface mb-8 flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="min-w-0">
            <h2 class="font-semibold">Verwaiste Dateien</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                <span x-text="orphans.count"></span> Datei(en) ohne Verwendung in Notizen oder deren Versionen ·
                <span x-text="formatBytes(orphans.bytes)"></span>
            </p>
        </div>
        <button
            type="button"
            class="btn btn-secondary"
            style="color: var(--color-danger);"
            :disabled="busy || orphans.count === 0"
            @click="purgeOrphans"
        >Aufräumen</button>
    </div>

    <div class="document-surface overflow-x-auto">
        <table class="w-full min-w-[52rem] text-sm">
            <thead>
            <tr class="text-left" style="color: var(--color-text-muted);">
                <th class="px-5 py-4 font-medium">Nutzer</th>
                <th class="py-4 font-medium">Seiten</th>
                <th class="py-4 font-medium">Aufgaben</th>
                <th class="py-4 font-medium">Bilder</th>
                <th class="py-4 font-medium">Speicher</th>
                <th class="py-4 font-medium">Kontingent</th>
                <th class="px-5 py-4"></th>
            </tr>
            </thead>
            <tbody>
            <template x-for="user in users" :key="user.id">
                <tr class="border-t align-top" style="border-color: var(--color-border);">
                    <td class="px-5 py-4">
                        <p class="font-medium" x-text="user.name || '—'"></p>
                        <p class="mt-0.5 text-xs" style="color: var(--color-text-muted);" x-text="user.email"></p>
                    </td>
                    <td class="py-4">
                        <span x-text="user.page_count"></span>
                        <span x-show="user.trashed_page_count > 0" class="text-xs" style="color: var(--color-text-muted);" x-text="' (+' + user.trashed_page_count + ' im Papierkorb)'"></span>
                    </td>
                    <td class="py-4" x-text="user.task_count"></td>
                    <td class="py-4" x-text="user.image_count"></td>
                    <td class="py-4">
                        <p x-text="usageLabel(user)"></p>
                        <div x-show="user.effective_quota_mb > 0" class="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full" style="background: var(--color-bg-subtle);">
                            <div class="h-full rounded-full" :style="usageBarStyle(user)"></div>
                        </div>
                        <button type="button" class="mt-2 text-xs font-medium underline" style="color: var(--color-accent);" :disabled="busy || user.image_count === 0" @click="compressUserImages(user)">Bilder komprimieren</button>
                    </td>
                    <td class="py-4" x-text="quotaLabel(user)"></td>
                    <td class="px-5 py-4">
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-quiet" :disabled="busy" @click="editQuota(user)">Kontingent</button>
                            <button type="button" class="btn btn-quiet" style="color: var(--color-danger);" :disabled="busy" @click="deleteUser(user)">Löschen</button>
                        </div>
                    </td>
                </tr>
            </template>
            <tr x-show="!loading && users.length === 0">
                <td colspan="7" class="py-8 text-center" style="color: var(--color-text-muted);">Noch keine Nutzer vorhanden.</td>
            </tr>
            <tr x-show="loading">
                <td colspan="7" class="py-8 text-center" style="color: var(--color-text-muted);">Lädt…</td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
