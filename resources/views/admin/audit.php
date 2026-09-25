<div class="mx-auto max-w-6xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminAudit">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Protokoll</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/admin" class="btn btn-secondary">Nutzer &amp; Speicher</a>
            <a href="/admin/backups" class="btn btn-secondary">Sicherungen</a>
            <a href="/app" class="btn btn-secondary">Zum Workspace</a>
        </div>
    </div>

    <p class="mb-6 text-sm" style="color: var(--color-text-muted);">
        Wer hat wann geteilt, gelöscht, exportiert oder Konten geändert. Inhalte von Notizen stehen nie im Protokoll.
        Einträge werden nach der eingestellten Aufbewahrungsfrist (<span class="font-medium">AUDIT_RETENTION_DAYS</span>) entfernt.
    </p>

    <p x-show="error" x-cloak x-text="error" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>

    <form @submit.prevent="applyFilter" class="document-surface mb-6 grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-5">
        <label class="text-sm">
            <span class="block text-xs font-medium" style="color: var(--color-text-muted);">Aktion</span>
            <select x-model="filter.action" class="mt-1 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
                <option value="">Alle</option>
                <template x-for="action in actions" :key="action">
                    <option :value="action" x-text="actionLabel(action)"></option>
                </template>
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-xs font-medium" style="color: var(--color-text-muted);">Nutzer-ID</span>
            <input type="text" inputmode="numeric" x-model="filter.user_id" class="mt-1 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
        </label>
        <label class="text-sm">
            <span class="block text-xs font-medium" style="color: var(--color-text-muted);">Von</span>
            <input type="date" x-model="filter.from" class="mt-1 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
        </label>
        <label class="text-sm">
            <span class="block text-xs font-medium" style="color: var(--color-text-muted);">Bis</span>
            <input type="date" x-model="filter.to" class="mt-1 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);">
        </label>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn btn-primary flex-1" :disabled="loading">Filtern</button>
            <button type="button" class="btn btn-quiet" :disabled="loading" @click="resetFilter">Zurücksetzen</button>
        </div>
    </form>

    <div class="document-surface overflow-x-auto">
        <table class="w-full min-w-[46rem] text-sm">
            <thead>
            <tr class="text-left" style="color: var(--color-text-muted);">
                <th class="px-5 py-4 font-medium">Zeitpunkt</th>
                <th class="py-4 font-medium">Nutzer</th>
                <th class="py-4 font-medium">Aktion</th>
                <th class="py-4 font-medium">Objekt</th>
                <th class="px-5 py-4 font-medium">Details</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="entry in entries" :key="entry.id">
                <tr class="border-t align-top" style="border-color: var(--color-border);">
                    <td class="whitespace-nowrap px-5 py-3" x-text="formatDate(entry.created_at)"></td>
                    <td class="py-3" x-text="userLabel(entry)"></td>
                    <td class="py-3" x-text="actionLabel(entry.action)"></td>
                    <td class="py-3" x-text="objectLabel(entry)"></td>
                    <td class="px-5 py-3 font-mono text-xs break-all" style="color: var(--color-text-muted);" x-text="detailLabel(entry)"></td>
                </tr>
            </template>
            <tr x-show="!loading && entries.length === 0">
                <td colspan="5" class="py-8 text-center" style="color: var(--color-text-muted);">Keine Einträge.</td>
            </tr>
            </tbody>
        </table>
    </div>
    <p x-show="loading" class="py-6 text-sm" style="color: var(--color-text-muted);">Lädt…</p>
    <div x-show="!loading && hasMore" x-cloak class="pt-4">
        <button type="button" class="btn btn-quiet w-full" @click="loadMore">Ältere Einträge laden</button>
    </div>
</div>
