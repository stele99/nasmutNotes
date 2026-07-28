<div class="mx-auto max-w-6xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminBackups">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Sicherungen</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/admin" class="btn btn-secondary">Nutzer &amp; Speicher</a>
            <a href="/admin/invites" class="btn btn-secondary">Einladungen</a>
            <a href="/app" class="btn btn-secondary">Zum Workspace</a>
        </div>
    </div>

    <p x-show="error" x-cloak x-text="error" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>
    <p x-show="message" x-cloak x-text="message" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-success) 14%, transparent);" role="status"></p>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Sicherungen</p>
            <p class="mt-1 text-2xl font-semibold" x-text="stats.snapshot_count || 0"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Letzte Sicherung</p>
            <p class="mt-1 text-2xl font-semibold" x-text="lastBackupLabel()"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Belegt</p>
            <p class="mt-1 text-2xl font-semibold" x-text="formatBytes(stats.stored_bytes)"></p>
        </div>
        <div class="document-surface p-5">
            <p class="text-sm" style="color: var(--color-text-muted);">Aufbewahrung</p>
            <p class="mt-1 text-2xl font-semibold" x-text="keepLabel()"></p>
        </div>
    </div>

    <?php /* Jeder Lauf ist ein vollständiger Stand; gespeichert wird jede Datei
             aber nur einmal. Deshalb kostet eine weitere Sicherung fast nichts. */ ?>
    <div class="document-surface mb-8 flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="min-w-0">
            <h2 class="font-semibold">Jetzt sichern</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Erzeugt einen vollständigen Stand aus Datenbank und Anhängen. Da unveränderte Dateien
                nur einmal gespeichert werden, belegt jeder weitere Lauf kaum zusätzlichen Platz.
            </p>
        </div>
        <button type="button" class="btn btn-primary" :disabled="busy" @click="create" x-text="createLabel()"></button>
    </div>

    <p x-show="loading" class="py-8 text-sm" style="color: var(--color-text-muted);">Lädt…</p>

    <div x-show="!loading && snapshots.length === 0" x-cloak class="document-surface p-8 text-center">
        <p class="text-sm" style="color: var(--color-text-muted);">
            Noch keine Sicherung vorhanden. Für den laufenden Betrieb sollte zusätzlich ein
            täglicher Cron-Eintrag <span class="font-medium" style="color: var(--color-text);">php bin/console.php backup:run</span> eingerichtet sein.
        </p>
    </div>

    <div x-show="!loading && snapshots.length > 0" x-cloak class="document-surface overflow-x-auto">
        <table class="w-full min-w-[46rem] text-sm">
            <thead>
            <tr class="text-left" style="color: var(--color-text-muted);">
                <th class="px-5 py-4 font-medium">Zeitpunkt</th>
                <th class="py-4 font-medium">Seiten</th>
                <th class="py-4 font-medium">Dateien</th>
                <th class="py-4 font-medium">Umfang</th>
                <th class="py-4 font-medium">Status</th>
                <th class="px-5 py-4"></th>
            </tr>
            </thead>
            <tbody>
            <template x-for="snapshot in snapshots" :key="snapshot.id">
                <tr class="border-t align-middle" style="border-color: var(--color-border);">
                    <td class="px-5 py-4">
                        <p class="font-medium" x-text="formatDate(snapshot.created_at)"></p>
                        <p class="mt-0.5 text-xs" style="color: var(--color-text-muted);" x-text="snapshot.id"></p>
                    </td>
                    <td class="py-4" x-text="pageLabel(snapshot)"></td>
                    <td class="py-4" x-text="fileLabel(snapshot)"></td>
                    <td class="py-4" x-text="formatBytes(snapshot.total_bytes)"></td>
                    <td class="py-4">
                        <span x-show="snapshot.complete" class="text-xs font-medium" style="color: var(--color-success);">vollständig</span>
                        <span x-show="!snapshot.complete" x-cloak class="text-xs font-medium" style="color: var(--color-danger);">unvollständig</span>
                    </td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a :href="downloadUrl(snapshot)" class="btn btn-secondary">Herunterladen</a>
                            <button type="button" class="btn btn-quiet" style="color: var(--color-danger);" :disabled="busy" @click="remove(snapshot)">Löschen</button>
                        </div>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
    </div>

    <?php /* Der Restore läuft bewusst nur über die CLI: Ein Knopf, der die
             Livedatenbank ersetzt, wäre nicht rückholbar (NFR-OPS-06). */ ?>
    <div class="document-surface mt-8 p-5">
        <h2 class="font-semibold">Wiederherstellen</h2>
        <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
            Das Zurückspielen ersetzt Datenbank und Anhänge und ist deshalb absichtlich nicht
            über die Oberfläche möglich. Auf dem Server, mit gestoppter Anwendung:
        </p>
        <pre class="mt-3 overflow-x-auto rounded-md p-3 text-xs" style="background: var(--color-bg-subtle);">php bin/console.php backup:list
php bin/console.php backup:verify &lt;id&gt;
php bin/console.php backup:restore &lt;id&gt;</pre>
        <p class="mt-3 text-sm" style="color: var(--color-text-muted);">
            <span class="font-medium" style="color: var(--color-text);">Wichtig:</span>
            Die Zugangsdaten in der <span class="font-medium" style="color: var(--color-text);">.env</span>
            sind bewusst nicht Teil der Sicherung. Sie gehören einmalig in einen Passwortmanager.
        </p>
    </div>
</div>
