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
    <aside x-show="isNotebookDrawerVisible()" x-cloak class="notebook-drawer fixed inset-y-0 left-0 z-50 flex w-full flex-col border-r md:w-80 xl:hidden" :aria-hidden="isNotebookDrawerVisible() ? 'false' : 'true'" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
        <?php include __DIR__ . '/partials/notebook_nav.php'; ?>
    </aside>
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <?php include __DIR__ . '/partials/notebook_dialog.php'; ?>
    <main class="workspace-main min-w-0 flex-1 h-dvh overflow-y-auto">
        <?php /* Die Übersicht ist mobil der Einstieg und hat keine eigene
                 Kopfzeile; der Weg zu den Notizbüchern liegt deshalb hier als
                 fixierter Schalter. */ ?>
        <button x-show="isMobileView('content')" x-cloak @click="showBooks()" class="sidebar-toggle fixed left-4 top-4 z-[100] flex border shadow-sm md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Notizbücher öffnen" x-icon="menu">
        </button>
        <section class="page-canvas mx-auto px-6 py-10 sm:px-10 sm:py-20 lg:py-28" x-data="pageList" @pages-changed.window="refresh">
            <?php /* Abstand bleibt so groß, dass der fixierte Schalter die
                     Überschrift nicht überlagert. */ ?>
            <div class="pt-6 sm:pt-12">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">Mein Workspace <span class="text-[0.5em]">by <span class="font-bold" style="color: var(--color-danger);">nasmut</span>Notes</span></h1>
                <p class="mt-2 hidden max-w-2xl text-lg sm:block" style="color: var(--color-text-muted);">Notizen, Aufgaben und Ideen an einem Ort.</p>
                <div class="mt-5 flex flex-row gap-3 sm:mt-8">
                    <button type="button" @click="createPage('note')" class="btn btn-primary flex-1 sm:flex-none">
                        <span x-icon="plus"></span>Neue Notiz
                    </button>
                    <button type="button" @click="createPage('task')" class="btn btn-secondary flex-1 sm:flex-none">
                        <span x-icon="list-todo"></span>Neue Aufgabenliste
                    </button>
                </div>
            </div>

            <div class="mt-8 max-w-2xl sm:mt-16">
                <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--color-border);">
                    <h2 class="text-xl font-semibold">Zuletzt bearbeitet</h2>
                    <span class="text-sm" style="color: var(--color-text-muted);" x-text="recentCountLabel()"></span>
                </div>
                <div class="divide-y divide-[color:var(--color-border)]">
                        <template x-for="page in recentPages()" :key="page.id">
                            <a :href="pageUrl(page)" @click.prevent="navigate(page)" class="flex items-start gap-3 py-4 hover:opacity-70">
                                <span x-show="page.type === 'note'" class="pt-0.5" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                                <span x-show="page.type === 'task'" class="pt-0.5" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                                <span x-show="page.is_shared" class="pt-0.5" style="color: var(--color-accent);" title="Geteilte Seite" x-icon="share-2"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-base font-medium" x-text="page.title"></p>
                                    <p class="mt-0.5 truncate text-sm" style="color: var(--color-text-muted);" x-text="pageSummary(page)"></p>
                                    <p class="mt-0.5 truncate text-xs" style="color: var(--color-text-muted); opacity: 0.8;" x-text="pageMeta(page)"></p>
                                </div>
                                <span class="pt-0.5" style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
                            </a>
                        </template>
                </div>
                <?php /* Sentinel für das Nachladen beim Scrollen; der Button bleibt als
                         Rückfalloption, wenn kein IntersectionObserver zur Verfügung steht. */ ?>
                <div x-ref="recentSentinel" x-show="hasMoreRecentPages()" class="pt-4">
                    <button type="button" @click="loadMoreRecentPages" class="btn btn-quiet w-full">Weitere Seiten laden</button>
                </div>
                <p x-show="!loading && pages.length === 0" class="py-8 text-base" style="color: var(--color-text-muted);">Deine ersten Seiten erscheinen hier.</p>
            </div>
        </section>
    </main>
</div>
