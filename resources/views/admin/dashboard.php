<div class="mx-auto max-w-6xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminDashboard">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Nutzer &amp; Speicher</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/admin/invites" class="btn btn-secondary">Einladungen</a>
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
