<?php /* Papierkorb-Einstieg am Fuß der Seitenleiste. */ ?>
<div class="shrink-0" x-data="trashPanel">
    <button type="button" @click="openDialog" class="icon-action shrink-0" title="Papierkorb" aria-label="Papierkorb" x-icon="trash"></button>

    <?php /* Wie die übrigen Dialoge in den Body teleportiert: Das <aside> trägt
             translate-x-Utilities und wäre sonst der Bezugsrahmen. */ ?>
    <template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-labelledby="trash-dialog-title"
        class="fixed inset-0 z-50 flex items-center justify-center p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeDialog"
        @keydown.escape.window="closeDialog"
    >
        <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--color-border);">
                <div>
                    <h2 id="trash-dialog-title" class="text-xl font-semibold" style="color: var(--color-text);">Papierkorb</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                        Gelöschte Seiten bleiben <span x-text="retentionDays"></span> Tage erhalten und lassen sich bis dahin wiederherstellen.
                    </p>
                </div>
                <button type="button" @click="closeDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <div class="min-h-[8rem] flex-1 overflow-y-auto px-5 py-4">
                <p x-show="loading" class="py-6 text-sm" style="color: var(--color-text-muted);">Lädt…</p>
                <p x-show="!loading && pages.length === 0" class="py-6 text-sm" style="color: var(--color-text-muted);">Der Papierkorb ist leer.</p>

                <ul class="space-y-2">
                    <template x-for="page in pages" :key="page.id">
                        <li class="flex items-center gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                            <span x-show="page.type === 'note'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                            <span x-show="page.type === 'task'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium" style="color: var(--color-text);" x-text="page.title"></p>
                                <p class="mt-0.5 truncate text-xs" style="color: var(--color-text-muted);">
                                    <span x-text="typeLabel(page)"></span> · <span x-text="remainingLabel(page)"></span>
                                </p>
                            </div>
                            <button type="button" class="btn btn-quiet shrink-0" :disabled="busy" @click="restore(page)">Wiederherstellen</button>
                            <button type="button" class="icon-action icon-action-danger shrink-0" :disabled="busy" title="Endgültig löschen" aria-label="Endgültig löschen" @click="purge(page)" x-icon="trash"></button>
                        </li>
                    </template>
                </ul>

                <p x-show="error" x-cloak x-text="error" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
            </div>

            <div class="flex items-center justify-between gap-3 border-t px-5 py-4" style="border-color: var(--color-border);">
                <button
                    type="button"
                    class="btn btn-quiet"
                    style="color: var(--color-danger);"
                    :disabled="busy || pages.length === 0"
                    @click="emptyTrash"
                >Papierkorb leeren</button>
                <button type="button" @click="closeDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Schließen</button>
            </div>
        </div>
    </div>
    </template>
</div>
