<script nonce="<?= e($cspNonce ?? '') ?>" data-cfasync="false">window.__CURRENT_PAGE_ID__ = <?= (int) $page['id'] ?>; window.__CURRENT_PAGE_TITLE__ = <?= json_encode($page['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.__CURRENT_PAGE_IS_SHARED__ = <?= !empty($page['is_shared']) ? 'true' : 'false' ?>; window.__CURRENT_PAGE_PERMISSION__ = <?= json_encode($page['share_permission'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.__CURRENT_PAGE_CAN_EDIT__ = <?= !empty($page['can_edit']) ? 'true' : 'false' ?>;</script>
<div class="workspace-shell flex h-dvh overflow-hidden" x-data="workspaceShell" @close-sidebar.window="showContent()" @pages-changed.window="refreshNotebooks" @touchstart="startMobileSwipe($event)" @touchmove="moveMobileSwipe($event)" @touchend="endMobileSwipe($event)" @touchcancel="cancelMobileSwipe()">
    <?php /* Mobil decken beide Leisten den Bildschirm vollständig ab; eine
             Überlagerung braucht nur die schmale Notizbuch-Schublade, die
             zwischen `md` und `xl` über der Seitenliste liegt. */ ?>
    <div
        x-show="notebookDrawerOpen"
        x-cloak
        class="fixed inset-0 z-[45] hidden bg-black/30 md:block xl:hidden"
        @click="notebookDrawerOpen = false"
    ></div>
    <aside class="notebook-rail relative hidden h-dvh shrink-0 flex-col border-r xl:flex" :style="notebookRailStyle()">
        <?php include __DIR__ . '/partials/notebook_nav.php'; ?>
        <div class="notebook-resize-handle" role="separator" aria-label="Notizbuchleiste in der Breite ändern" aria-orientation="vertical" tabindex="0" :aria-valuenow="notebookRailWidth" aria-valuemin="220" aria-valuemax="420" @pointerdown="startNotebookResize" @keydown.left.prevent="resizeNotebookRailBy(-16)" @keydown.right.prevent="resizeNotebookRailBy(16)"></div>
    </aside>
    <?php /* Wie .page-sidebar bleibt die Schublade immer im DOM und wird per
             Transform ein-/ausgefahren, statt über x-show schlagartig
             umzuschalten - sonst fehlt hier die Animation, die die Seiten-
             Sidebar beim Wechsel zwischen den mobilen Ebenen hat. */ ?>
    <aside class="notebook-drawer fixed inset-y-0 left-0 z-50 flex w-full -translate-x-full flex-col border-r transition-transform duration-200 md:w-80 xl:hidden" :class="{ 'translate-x-0': isNotebookDrawerVisible() }" :aria-hidden="isNotebookDrawerVisible() ? 'false' : 'true'" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
        <?php include __DIR__ . '/partials/notebook_nav.php'; ?>
    </aside>
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <?php include __DIR__ . '/partials/notebook_dialog.php'; ?>
    <main
        class="workspace-main min-w-0 flex-1 h-dvh overflow-y-auto"
        x-data="pageShare"
        data-page-id="<?= (int) $page['id'] ?>"
        data-page-title="<?= e((string) $page['title']) ?>"
        data-page-is-shared="<?= !empty($page['is_shared']) ? '1' : '0' ?>"
        data-page-permission="<?= e((string) ($page['share_permission'] ?? '')) ?>"
        data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>"
    >
        <?php /* Der Rückweg aus dem Seiteninhalt liegt mobil im Kopf der Seite
                 selbst (siehe page_note.php / page_task.php) - dort steht er
                 neben den übrigen Aktionen, statt den Text zu überlagern. */ ?>
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
                            <span class="block text-sm" style="color: var(--color-text-muted);">Jeder mit dem Link kann die Seite ohne Anmeldung lesen.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                        <input x-model="permission" type="radio" value="read_copy" class="mt-1">
                        <span>
                            <span class="block font-medium">Lesen und kopieren</span>
                            <span class="block text-sm" style="color: var(--color-text-muted);">Ohne Anmeldung lesen; angemeldete Nutzer können eine unabhängige Kopie mit Bildern und Anhängen erstellen.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                        <input x-model="permission" type="radio" value="write" class="mt-1">
                        <span>
                            <span class="block font-medium">Lesen und schreiben</span>
                            <span class="block text-sm" style="color: var(--color-text-muted);">Anmeldung erforderlich. Die Seite wird gemeinsam im Workspace bearbeitet.</span>
                        </span>
                    </label>
                </fieldset>

                <div x-show="existingShares.length > 0" x-cloak class="mt-5 flex items-center justify-between gap-4 rounded-lg border p-3" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                    <div class="min-w-0">
                        <p class="text-sm font-medium" x-text="activeSharesLabel()"></p>
                        <p class="mt-0.5 text-xs" style="color: var(--color-text-muted);">Widerruft alle Links und Zugriffe auf diese Seite.</p>
                    </div>
                    <button
                        type="button"
                        @click="stopSharing"
                        :disabled="stoppingSharing"
                        class="shrink-0 rounded-md px-3 py-2 text-sm font-medium"
                        style="color: var(--color-danger); background: color-mix(in srgb, var(--color-danger) 10%, transparent);"
                        x-text="stoppingSharing ? 'Beende…' : 'Teilen beenden'"
                    ></button>
                </div>

                <div x-show="generatedLink" x-cloak class="mt-5 rounded-lg p-3" style="background: var(--color-bg-subtle);">
                    <label for="generated-share-link" class="block text-sm font-medium">Freigabe-Link</label>
                    <div class="mt-2 flex gap-2">
                        <input id="generated-share-link" x-model="generatedLink" readonly class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <button type="button" @click="copyLink" class="rounded-md px-3 py-2 text-sm font-medium text-white" style="background: var(--color-accent);" x-text="copyLabel"></button>
                    </div>
                </div>

                <p x-show="errorMessage" x-text="errorMessage" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
                <p x-show="successMessage" x-text="successMessage" class="mt-4 text-sm" style="color: var(--color-success);" role="status"></p>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="closeShareDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Schließen</button>
                    <button type="submit" class="rounded-md px-4 py-2 text-sm font-medium text-white" style="background: var(--color-accent);" :disabled="generating" x-text="generating ? 'Erzeuge…' : 'Link erzeugen und kopieren'"></button>
                </div>
            </form>
        </div>
    </main>
</div>
