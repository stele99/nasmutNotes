<script nonce="<?= e($cspNonce ?? '') ?>">window.__CURRENT_PAGE_ID__ = <?= (int) $page['id'] ?>; window.__CURRENT_PAGE_TITLE__ = <?= json_encode($page['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<div class="flex min-h-screen" x-data="workspaceShell">
    <div
        x-show="sidebarOpen"
        x-cloak
        class="fixed inset-0 z-30 bg-black/30 md:hidden"
        @click="sidebarOpen = false"
    ></div>
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="workspace-main min-w-0 flex-1 h-screen overflow-y-auto">
        <button @click="sidebarOpen = true" class="fixed left-4 top-4 z-20 rounded-md border p-2 shadow-sm md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Menü öffnen" x-icon="menu">
        </button>
        <?php if ($page['type'] === 'note'): ?>
            <?php include __DIR__ . '/page_note.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/page_task.php'; ?>
        <?php endif; ?>
    </main>
</div>
