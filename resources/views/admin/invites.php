<div class="mx-auto max-w-5xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminInvites">
    <div class="mb-8 flex items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Einladungen</h1>
        </div>
        <a href="/app" class="rounded-lg border px-4 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg);">Zum Workspace</a>
    </div>

    <form @submit.prevent="create" class="document-surface mb-8 space-y-5 p-6 sm:p-8">
        <h2 class="text-xl font-semibold">Neue Einladung erstellen</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <input type="email" x-model="form.email" placeholder="E-Mail (optional)" class="rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
            <input type="text" x-model="form.note" placeholder="Notiz (optional)" class="rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
            <input type="number" min="1" x-model.number="form.max_uses" placeholder="Max. Nutzungen" class="rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
            <input type="number" min="1" x-model.number="form.ttl_days" placeholder="Gültigkeit (Tage)" class="rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
        </div>
        <button type="submit" :disabled="creating" class="rounded-lg px-5 py-3 text-base font-medium text-white" style="background: var(--color-accent);">
            Einladung erstellen
        </button>

        <div x-show="lastCreatedUrl" class="rounded-lg p-4 text-base break-all" style="background: var(--color-bg-subtle);">
            Link (nur jetzt sichtbar): <span x-text="lastCreatedUrl"></span>
        </div>
    </form>

    <div class="document-surface overflow-x-auto">
    <table class="w-full min-w-[700px] text-base">
        <thead>
        <tr class="text-left" style="color: var(--color-text-muted);">
            <th class="px-5 py-4">E-Mail</th>
            <th class="py-4">Notiz</th>
            <th class="py-4">Status</th>
            <th class="py-4">Nutzung</th>
            <th class="py-4">Ablauf</th>
            <th class="py-4"></th>
        </tr>
        </thead>
        <tbody>
        <template x-for="invite in invites" :key="invite.id">
            <tr class="border-t" style="border-color: var(--color-border);">
                <td class="px-5 py-4" x-text="invite.email || '—'"></td>
                <td x-text="invite.note || '—'"></td>
                <td x-text="statusLabel(invite.status)"></td>
                <td x-text="invite.used_count + ' / ' + invite.max_uses"></td>
                <td x-text="invite.expires_at"></td>
                <td>
                    <button x-show="invite.status === 'open'" @click="revoke(invite.id)" class="text-sm" style="color: var(--color-danger);">
                        Widerrufen
                    </button>
                </td>
            </tr>
        </template>
        <tr x-show="!loading && invites.length === 0">
            <td colspan="6" class="py-8 text-center" style="color: var(--color-text-muted);">Noch keine Einladungen vorhanden.</td>
        </tr>
        </tbody>
    </table>
    </div>
</div>
