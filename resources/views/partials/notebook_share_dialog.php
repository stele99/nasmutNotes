<div
    x-show="notebookShareDialogOpen"
    x-cloak
    class="fixed inset-0 z-[80] flex items-center justify-center p-5"
    style="background-color: rgb(0 0 0 / 0.4);"
    role="dialog"
    aria-modal="true"
    aria-labelledby="notebook-share-dialog-title"
    @click.self="closeNotebookShareDialog"
    @keydown.escape.window="closeNotebookShareDialog"
>
    <?php /* Gleiche Dialogform wie das Notizbuch-Dialogfenster: Bei jedem
             Absenden entsteht ein Teilnehmer, die Liste lädt sich danach
             selbst neu. Fehler erscheinen eingangsbezogen. */ ?>
    <form @submit.prevent="addNotebookParticipant" class="w-full max-w-sm rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="notebook-share-dialog-title" class="text-xl font-semibold" x-text="notebookShareTitle()"></h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);" x-text="notebookShareSubtitle()"></p>
            </div>
            <button type="button" @click="closeNotebookShareDialog" :disabled="notebookShareSaving" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>
        <label for="notebook-share-email" class="mt-5 block text-sm font-medium">Teilen per E-Mail-Adresse</label>
        <div class="mt-2 flex gap-2">
            <input x-ref="notebookShareEmail" id="notebook-share-email" x-model="notebookShareEmail" :disabled="notebookShareSaving" maxlength="254" autocomplete="off" type="email" class="min-w-0 flex-1 rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);" placeholder="name@example.de">
            <button type="submit" :disabled="notebookShareSaving" class="btn btn-primary shrink-0" x-text="notebookShareSaving ? 'Füge hinzu…' : 'Hinzufügen'"></button>
        </div>
        <p class="mt-2 text-xs" style="color: var(--color-text-muted);">Nur bereits registrierte Nasmut-Notes-Nutzer können hinzugefügt werden.</p>
        <p x-show="notebookShareError" x-cloak x-text="notebookShareError" class="mt-2 text-sm" style="color: var(--color-danger);" role="alert"></p>
        <p class="mt-5 text-sm font-medium">Teilnehmer</p>
        <p x-show="notebookShareParticipantsLoading" x-cloak class="mt-2 text-sm" style="color: var(--color-text-muted);">Teilnehmer werden geladen…</p>
        <ul x-show="!notebookShareParticipantsLoading" class="mt-2 space-y-2">
            <template x-for="participant in notebookShareParticipants" :key="participant.id">
                <li class="flex items-center gap-2 text-sm">
                    <span class="relative inline-flex size-7 shrink-0 items-center justify-center rounded-full border-2 text-[10px] font-semibold uppercase" style="border-color: var(--color-bg); background: color-mix(in srgb, var(--color-accent) 16%, var(--color-bg)); color: var(--color-accent);" x-text="participantInitials(participant)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium" x-text="participant.name"></p>
                        <p class="truncate text-xs" style="color: var(--color-text-muted);" x-text="participant.email"></p>
                    </div>
                    <button type="button" @click="removeNotebookParticipant(participant)" :disabled="notebookShareRemovingId !== null" class="icon-action icon-action-danger shrink-0" aria-label="Teilnehmer entfernen" x-icon="x"></button>
                </li>
            </template>
            <li x-show="notebookShareParticipants.length === 0" x-cloak class="text-sm" style="color: var(--color-text-muted);">Noch keine Teilnehmer.</li>
        </ul>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="closeNotebookShareDialog" :disabled="notebookShareSaving" class="btn btn-quiet">Schließen</button>
        </div>
    </form>
</div>
