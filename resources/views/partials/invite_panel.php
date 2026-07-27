<div class="mt-2 border-t pt-2" style="border-color: var(--color-border);" x-data="userInvites">
    <button type="button" @click="openDialog" class="notebook-item">
        <span x-icon="mail"></span>
        <span class="min-w-0 flex-1 truncate">Person einladen</span>
    </button>

    <?php /* Wie beim Offline-Dialog: das <aside> erzeugt über translate-x einen
             Containing Block, deshalb wird der Dialog in den Body teleportiert. */ ?>
    <template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeDialog"
        @keydown.escape.window="closeDialog"
    >
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold" style="color: var(--color-text);">Person einladen</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Erzeuge einen Registrierungslink für jemanden, der die App nutzen soll.</p>
                </div>
                <button type="button" @click="closeDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
            </div>

            <form @submit.prevent="create" class="mt-5 space-y-3">
                <div>
                    <label class="block text-sm font-medium" style="color: var(--color-text);" for="invite-email">E-Mail (optional)</label>
                    <input id="invite-email" type="email" x-model="form.email" placeholder="name@example.com" class="mt-1.5 w-full rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);">
                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Ist eine Adresse hinterlegt, funktioniert der Link nur mit genau diesem Google-Konto.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium" style="color: var(--color-text);" for="invite-note">Notiz (optional)</label>
                    <input id="invite-note" type="text" maxlength="200" x-model="form.note" placeholder="Wofür ist die Einladung?" class="mt-1.5 w-full rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium" style="color: var(--color-text);" for="invite-max-uses">Nutzungen</label>
                        <input id="invite-max-uses" type="number" min="1" max="50" x-model.number="form.max_uses" class="mt-1.5 w-full rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);">
                    </div>
                    <div>
                        <label class="block text-sm font-medium" style="color: var(--color-text);" for="invite-ttl">Gültig (Tage)</label>
                        <input id="invite-ttl" type="number" min="1" max="365" x-model.number="form.ttl_days" class="mt-1.5 w-full rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full" :disabled="creating" x-text="creating ? 'Erstelle…' : 'Einladungslink erstellen'"></button>
            </form>

            <div x-show="lastCreatedUrl" x-cloak class="mt-4 rounded-lg p-3" style="background: var(--color-bg-subtle);">
                <p class="text-sm font-medium" style="color: var(--color-text);">Einladungslink (nur jetzt sichtbar)</p>
                <p class="mt-1.5 break-all text-xs" style="color: var(--color-text-muted);" x-text="lastCreatedUrl"></p>
                <?php /* Mobil ist das systemeigene Teilen der direkte Weg (WhatsApp, Mail,
                         …), auf dem Desktop das Kopieren in die Zwischenablage. Die jeweils
                         passende Aktion steht deshalb als Hauptschalter vorn. */ ?>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <button type="button" x-show="canShare()" @click="share" class="btn btn-primary flex-1">
                        <span x-icon="share-2"></span>Link teilen
                    </button>
                    <button type="button" x-show="canShare()" @click="copyLink" class="btn btn-secondary flex-1" x-text="copyLabel"></button>
                    <button type="button" x-show="!canShare()" @click="copyLink" class="btn btn-primary flex-1" x-text="copyLabel"></button>
                </div>
            </div>

            <p x-show="error" x-cloak x-text="error" class="mt-4 text-sm" style="color: var(--color-danger);"></p>

            <div class="mt-5 border-t pt-4" style="border-color: var(--color-border);">
                <h3 class="font-semibold" style="color: var(--color-text);">Meine Einladungen</h3>
                <p x-show="loading" class="mt-2 text-sm" style="color: var(--color-text-muted);">Lädt…</p>
                <p x-show="!loading && invites.length === 0" class="mt-2 text-sm" style="color: var(--color-text-muted);">Du hast noch niemanden eingeladen.</p>
                <ul class="mt-2 space-y-2">
                    <template x-for="invite in invites" :key="invite.id">
                        <li class="flex items-center gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium" style="color: var(--color-text);" x-text="invite.note || invite.email || 'Einladungslink'"></p>
                                <p class="mt-0.5 truncate text-xs" style="color: var(--color-text-muted);" x-text="inviteSummary(invite)"></p>
                            </div>
                            <button type="button" x-show="invite.status === 'open'" @click="revoke(invite)" class="shrink-0 text-sm font-medium" style="color: var(--color-danger);">Widerrufen</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="button" @click="closeDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Schließen</button>
            </div>
        </div>
    </div>
    </template>
</div>
