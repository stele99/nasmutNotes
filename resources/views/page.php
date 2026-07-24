<script nonce="<?= e($cspNonce ?? '') ?>">window.__CURRENT_PAGE_ID__ = <?= (int) $page['id'] ?>; window.__CURRENT_PAGE_TITLE__ = <?= json_encode($page['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.__CURRENT_PAGE_IS_SHARED__ = <?= !empty($page['is_shared']) ? 'true' : 'false' ?>; window.__CURRENT_PAGE_PERMISSION__ = <?= json_encode($page['share_permission'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.__CURRENT_PAGE_CAN_EDIT__ = <?= !empty($page['can_edit']) ? 'true' : 'false' ?>;</script>
<div class="flex min-h-screen" x-data="workspaceShell">
    <div
        x-show="sidebarOpen"
        x-cloak
        class="fixed inset-0 z-30 bg-black/30 md:hidden"
        @click="sidebarOpen = false"
    ></div>
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="workspace-main min-w-0 flex-1 h-screen overflow-y-auto" x-data="pageShare">
        <button @click="sidebarOpen = true" class="fixed left-4 top-4 z-20 rounded-md border p-2 shadow-sm md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Menü öffnen" x-icon="menu">
        </button>
        <?php if ($page['type'] === 'note'): ?>
            <?php include __DIR__ . '/page_note.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/page_task.php'; ?>
        <?php endif; ?>

        <div
            x-show="shareDialogOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-5"
            style="background-color: rgb(0 0 0 / 0.4);"
            @click.self="closeShareDialog"
            @keydown.escape.window="closeShareDialog"
        >
            <form @submit.prevent="generateShareLink" class="w-full max-w-md rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold">Seite teilen</h2>
                        <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Erstelle einen Link für diese Seite.</p>
                    </div>
                    <button type="button" @click="closeShareDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
                </div>

                <fieldset class="mt-6 space-y-3">
                    <legend class="text-sm font-medium">Berechtigung</legend>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                        <input x-model="permission" type="radio" value="read" class="mt-1">
                        <span>
                            <span class="block font-medium">Nur lesen</span>
                            <span class="block text-sm" style="color: var(--color-text-muted);">Die Seite kann angesehen, aber nicht geändert werden.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                        <input x-model="permission" type="radio" value="write" class="mt-1">
                        <span>
                            <span class="block font-medium">Lesen und schreiben</span>
                            <span class="block text-sm" style="color: var(--color-text-muted);">Der Link berechtigt zum Bearbeiten der Inhalte.</span>
                        </span>
                    </label>
                </fieldset>

                <div x-show="generatedLink" x-cloak class="mt-5 rounded-lg p-3" style="background: var(--color-bg-subtle);">
                    <label for="generated-share-link" class="block text-sm font-medium">Freigabe-Link</label>
                    <div class="mt-2 flex gap-2">
                        <input id="generated-share-link" x-model="generatedLink" readonly class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <button type="button" @click="copyLink" class="rounded-md px-3 py-2 text-sm font-medium text-white" style="background: var(--color-accent);" x-text="copyLabel"></button>
                    </div>
                </div>

                <p x-show="errorMessage" x-text="errorMessage" class="mt-4 text-sm" style="color: var(--color-danger);"></p>
                <p x-show="successMessage" x-text="successMessage" class="mt-4 text-sm" style="color: var(--color-success);"></p>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="closeShareDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Schließen</button>
                    <button type="submit" class="rounded-md px-4 py-2 text-sm font-medium text-white" style="background: var(--color-accent);" :disabled="generating" x-text="generating ? 'Erzeuge…' : 'Link erzeugen und kopieren'"></button>
                </div>
            </form>
        </div>
    </main>
</div>
