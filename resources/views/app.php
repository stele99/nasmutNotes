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
    <main class="workspace-main min-w-0 flex-1 h-dvh overflow-y-auto">
        <?php /* Die Übersicht ist mobil der Einstieg und hat keine eigene
                 Kopfzeile; der Weg zu den Notizbüchern liegt deshalb hier als
                 fixierter Schalter. */ ?>
        <?php /* Größe und linke Position folgen dem Logo darunter (size-10
                 sm:size-14, px-6 sm:px-10 der Sektion) - sonst wirken Button
                 und Logo wie zwei unabhängige Elemente. */ ?>
        <button x-show="isMobileView('content')" x-cloak @click="showBooks()" class="sidebar-toggle home-menu-toggle fixed left-6 top-4 z-[100] flex border shadow-sm sm:left-10 md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Notizbücher öffnen" x-icon="menu">
        </button>
        <section class="page-canvas mx-auto px-6 py-10 sm:px-10 sm:py-20 lg:py-28" x-data="pageList" @pages-changed.window="refresh">
            <?php /* Abstand bleibt so groß, dass der fixierte Schalter die
                     Überschrift nicht überlagert. */ ?>
            <div class="pt-6 sm:pt-12">
                <div class="flex items-center gap-3 sm:gap-4">
                    <img src="/icon/logo-mark.svg" alt="" width="56" height="56" class="size-10 shrink-0 sm:size-14">
                    <h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">Mein Workspace</h1>
                </div>
                <p class="mt-2 hidden max-w-2xl text-lg sm:block" style="color: var(--color-text-muted);">Notizen, Aufgaben und Ideen an einem Ort.</p>
                <div class="mt-5 flex flex-row flex-wrap gap-3 sm:mt-8">
                    <button type="button" @click="createPage('note')" class="btn btn-primary flex-1 sm:flex-none">
                        <span x-icon="plus"></span>Neue Notiz
                    </button>
                    <button type="button" @click="createPage('task')" class="btn btn-secondary flex-1 sm:flex-none">
                        <span x-icon="list-todo"></span>Neue Aufgabenliste
                    </button>
                    <?php /* Logbuch: Einträge mit Zeitpunkt und eigenen Spalten (FR-LOG-01). */ ?>
                    <button type="button" @click="createPage('log')" class="btn btn-secondary flex-1 sm:flex-none">
                        <span x-icon="scroll-text"></span>Neues Logbuch
                    </button>
                    <?php /* Sprachnotiz: Aufnehmen, transkribieren lassen und als fertige
                             Notiz mit Überschrift und Notizbuch anlegen (FR-VOICE-01..04). */ ?>
                    <?php if (!empty($voiceEnabled)): ?>
                        <button type="button" x-show="voiceSupported" x-cloak @click="startVoice" :disabled="isVoiceBusy()" class="btn btn-secondary flex-1 sm:flex-none">
                            <span x-icon="mic"></span>Sprachnotiz
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($voiceEnabled)): ?>
                    <div class="mt-3 max-w-2xl"><?php include __DIR__ . '/partials/voice_panel.php'; ?></div>
                <?php endif; ?>
                <form @submit.prevent="search" class="mt-4 flex max-w-md items-center gap-2 rounded-md border px-3 py-2.5" style="border-color: var(--color-border); background: var(--color-bg);">
                    <span style="color: var(--color-text-muted);" x-icon="search"></span>
                    <input x-model="searchQuery" @input="if (!searchQuery) searchResults = []" type="search" placeholder="Suchen und Enter drücken…" class="sidebar-search min-w-0 flex-1 bg-transparent outline-none">
                    <button type="button" x-show="searchQuery" x-cloak @click="clearSearch" class="icon-action" aria-label="Suche zurücksetzen" x-icon="x"></button>
                </form>
            </div>

            <div class="mt-8 max-w-2xl sm:mt-16">
                <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--color-border);">
                    <h2 class="text-xl font-semibold" x-text="searchQuery.trim() !== '' ? 'Suchergebnisse' : 'Zuletzt bearbeitet'"></h2>
                    <span class="text-sm" style="color: var(--color-text-muted);" x-text="searchQuery.trim() !== '' ? searchResultsLabel() : recentCountLabel()"></span>
                </div>
                <p x-show="searchLoading" x-cloak class="py-6 text-sm" style="color: var(--color-text-muted);">Suche läuft…</p>
                <div x-show="!searchLoading" class="divide-y divide-[color:var(--color-border)]">
                        <template x-for="page in (searchQuery.trim() !== '' ? searchResults : recentPages())" :key="page.id">
                            <a :href="pageUrl(page)" @click.prevent="navigate(page)" class="flex items-start gap-3 py-4 hover:opacity-70">
                                <span x-show="page.type === 'note'" class="pt-0.5" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                                <span x-show="page.type === 'task'" class="pt-0.5" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                                <span x-show="page.type === 'log'" class="pt-0.5" style="color: var(--color-text-muted);" x-icon="scroll-text"></span>
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
                         Rückfalloption, wenn kein IntersectionObserver zur Verfügung steht.
                         Die Suche liefert bereits das komplette Ergebnis, braucht also kein
                         Nachladen. */ ?>
                <div x-ref="recentSentinel" x-show="searchQuery.trim() === '' && hasMoreRecentPages()" class="pt-4">
                    <button type="button" @click="loadMoreRecentPages" class="btn btn-quiet w-full">Weitere Seiten laden</button>
                </div>
                <p x-show="!loading && searchQuery.trim() === '' && pages.length === 0" class="py-8 text-base" style="color: var(--color-text-muted);">Deine ersten Seiten erscheinen hier.</p>
                <p x-show="!searchLoading && searchQuery.trim() !== '' && searchResults.length === 0" class="py-8 text-base" style="color: var(--color-text-muted);">Keine Treffer für „<span x-text="searchQuery"></span>“.</p>
            </div>
        </section>
    </main>
</div>
