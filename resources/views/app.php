<div class="flex min-h-screen" x-data="workspaceShell">
    <div
        x-show="sidebarOpen"
        x-cloak
        class="fixed inset-0 z-30 bg-black/30 md:hidden"
        @click="sidebarOpen = false"
    ></div>
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="workspace-main min-w-0 flex-1 overflow-y-auto">
        <button @click="sidebarOpen = true" class="fixed left-4 top-4 z-20 rounded-md border p-2 shadow-sm md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Menü öffnen" x-icon="menu">
        </button>
        <section class="page-canvas mx-auto px-6 py-20 sm:px-10 lg:py-28" x-data="pageList">
            <div class="page-toolbar flex items-center gap-2">
                <span x-icon="home"></span>
                Übersicht
            </div>
            <div class="pt-12">
                <div class="flex size-14 items-center justify-center rounded-xl" style="background: color-mix(in srgb, var(--color-accent) 12%, transparent); color: var(--color-accent);" x-icon="folder-kanban"></div>
                <h1 class="mt-5 text-4xl font-semibold tracking-tight sm:text-5xl">Mein Workspace</h1>
                <p class="mt-3 max-w-2xl text-lg" style="color: var(--color-text-muted);">Notizen, Aufgaben und Ideen an einem Ort.</p>
                <div class="mt-8 flex flex-col gap-2 sm:flex-row">
                    <button @click="createPage('note')" class="flex items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-medium text-white" style="background: var(--color-accent);"><span x-icon="plus"></span>Neue Notiz</button>
                    <button @click="createPage('task')" class="flex items-center justify-center gap-2 rounded-md border px-4 py-2.5 text-sm font-medium" style="border-color: var(--color-border);"><span x-icon="list-todo"></span>Neue Aufgabenliste</button>
                </div>
            </div>

            <div class="mt-16 max-w-2xl">
                <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--color-border);">
                    <h2 class="text-xl font-semibold">Zuletzt bearbeitet</h2>
                    <span class="text-sm" style="color: var(--color-text-muted);" x-text="loading ? 'Lädt…' : pages.length + ' Seiten'"></span>
                </div>
                <div class="divide-y" style="border-color: var(--color-border);">
                        <template x-for="page in recentPages()" :key="page.id">
                            <a :href="pageUrl(page)" @click.prevent="navigate(page)" class="flex items-center gap-3 py-4 hover:opacity-70">
                                <span x-show="page.type === 'note'" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                                <span x-show="page.type === 'task'" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                                <span x-show="page.is_shared" style="color: var(--color-accent);" title="Geteilte Seite" x-icon="share-2"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-base font-medium" x-text="page.title"></p>
                                    <p class="mt-0.5 text-sm" style="color: var(--color-text-muted);" x-text="page.type === 'note' ? 'Notiz' : 'Aufgabenliste'"></p>
                                </div>
                                <span style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
                            </a>
                        </template>
                </div>
                <p x-show="!loading && pages.length === 0" class="py-8 text-base" style="color: var(--color-text-muted);">Deine ersten Seiten erscheinen hier.</p>
            </div>
        </section>
    </main>
</div>
