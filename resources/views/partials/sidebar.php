<aside
    x-data="pageList"
    @pages-changed.window="refresh"
    @collection-changed.window="setCollection"
    @navigate-home.window="navigateTo('/app')"
    @page-drop-move.window="movePagesByIds($event.detail.pageIds, $event.detail.notebookId)"
    @page-drop-trash.window="trashPagesByIds($event.detail.pageIds)"
    class="page-sidebar fixed inset-y-0 left-0 z-40 flex w-full -translate-x-full flex-col border-r transition-transform duration-200 md:sticky md:top-0 md:w-90 md:translate-x-0 xl:w-[22rem]"
    :class="{ 'translate-x-0': isPageSidebarVisible() }"
    :aria-hidden="isMobile && !isPageSidebarVisible() ? 'true' : 'false'"
    style="border-color: var(--color-border); background-color: var(--color-bg-subtle);"
>
    <?php /* min-h auf denselben Wert wie in notebook_nav.php - siehe dort. */ ?>
    <div class="relative flex min-h-[3.75rem] items-center gap-2 border-b px-4 pb-3 pt-5" style="border-color: var(--color-border);">
        <?php /* Zurück zu den Notizbüchern: mobil die vorige Stapelebene, auf
                 mittleren Breiten die überlagernde Schublade. */ ?>
        <button type="button" @click="isMobile ? showBooks() : (notebookDrawerOpen = true)" class="icon-action flex shrink-0 items-center border p-2 xl:hidden" style="border-color: var(--color-border);" title="Zurück zur Notizbuchauswahl" aria-label="Notizbücher öffnen" :aria-expanded="notebookDrawerOpen" x-icon="chevron-left"></button>
        <div class="absolute left-1/2 flex max-w-[calc(100%_-_7rem)] -translate-x-1/2 items-center justify-center gap-2 md:static md:min-w-0 md:max-w-none md:flex-1 md:translate-x-0 md:justify-start">
            <span x-show="activeCollection === 'notebook'" x-cloak class="notebook-appearance shrink-0" :style="activeNotebookIconStyle()"><span x-show="activeNotebookIconIs('book-open')" x-icon="book-open:size-5"></span><span x-show="activeNotebookIconIs('folder')" x-icon="folder:size-5"></span><span x-show="activeNotebookIconIs('briefcase')" x-icon="briefcase:size-5"></span><span x-show="activeNotebookIconIs('house')" x-icon="house:size-5"></span><span x-show="activeNotebookIconIs('plane')" x-icon="plane:size-5"></span><span x-show="activeNotebookIconIs('heart')" x-icon="heart:size-5"></span><span x-show="activeNotebookIconIs('lightbulb')" x-icon="lightbulb:size-5"></span><span x-show="activeNotebookIconIs('laptop')" x-icon="laptop:size-5"></span><span x-show="activeNotebookIconIs('wrench')" x-icon="wrench:size-5"></span><span x-show="activeNotebookIconIs('utensils')" x-icon="utensils:size-5"></span><span x-show="activeNotebookIconIs('graduation-cap')" x-icon="graduation-cap:size-5"></span><span x-show="activeNotebookIconIs('star')" x-icon="star:size-5"></span></span>
            <h2 class="truncate text-base font-semibold" x-text="collectionLabel()"></h2>
        </div>
        <button type="button" @click="showContent()" class="icon-action ml-auto md:hidden" aria-label="Seitenauswahl schließen" x-icon="x"></button>
    </div>
    <div class="space-y-3 p-3">
        <div x-show="activeCollection !== 'trash'" class="flex items-center gap-2">
            <form @submit.prevent="search" class="flex min-w-0 flex-1 items-center gap-2 rounded-md border px-2 py-2" style="border-color: var(--color-border); background: var(--color-bg);"><span x-icon="search"></span><input x-model="searchQuery" @input="nearbyActive = false; if (!searchQuery) searchResults = []" type="search" placeholder="Suchen und Enter drücken…" class="sidebar-search min-w-0 flex-1 bg-transparent outline-none"><button type="button" x-show="searchQuery" x-cloak @click="clearSearch" class="icon-action" aria-label="Suche zurücksetzen" x-icon="x"></button></form>
            <?php /* Umkreissuche (FR-NOTE-27): Seiten und Logbuch-Einträge mit
                     Standort im gewählten Umkreis um einen Punkt auf der Karte. */ ?>
            <button type="button" @click="openNearbyDialog" class="icon-action shrink-0 border p-2" :style="nearbyActive ? 'color: var(--color-accent); border-color: var(--color-accent);' : 'border-color: var(--color-border);'" title="In der Nähe suchen" aria-label="In der Nähe suchen" x-icon="map-pin"></button>
        </div>
        <div x-show="activeCollection !== 'trash'" class="flex gap-2">
            <button type="button" @click="createPage('note')" :disabled="activeCollection === 'shared' || activeCollection === 'favorites'" class="btn btn-quiet flex-1"><span x-icon="plus"></span>Notiz</button>
            <button type="button" @click="createPage('task')" :disabled="activeCollection === 'shared' || activeCollection === 'favorites'" class="btn btn-quiet flex-1"><span x-icon="plus"></span>Aufgaben</button>
            <button type="button" @click="createPage('log')" :disabled="activeCollection === 'shared' || activeCollection === 'favorites'" class="btn btn-quiet flex-1"><span x-icon="plus"></span>Log</button>
            <?php /* Das Notizbuch der Sprachnotiz leitet der Dienst aus dem Inhalt ab -
                     der Knopf hängt deshalb nicht an der gewählten Sammlung. */ ?>
            <?php if (!empty($voiceEnabled)): ?>
                <button type="button" x-show="voiceSupported" x-cloak @click="startOrOpenPicker" :disabled="isVoiceBusy()" class="btn btn-quiet shrink-0" title="Sprachnotiz aufnehmen" aria-label="Sprachnotiz aufnehmen"><span x-icon="mic"></span></button>
            <?php endif; ?>
        </div>
        <?php if (!empty($voiceEnabled)): ?>
            <?php include __DIR__ . '/voice_panel.php'; ?>
            <?php include __DIR__ . '/voice_template_picker.php'; ?>
        <?php endif; ?>
        <p x-show="activeCollection === 'shared' || activeCollection === 'favorites'" class="text-xs" style="color: var(--color-text-muted);">In dieser Sammlung können keine Seiten angelegt werden.</p>
        <div x-show="activeCollection === 'trash'" x-cloak>
            <p class="text-xs" style="color: var(--color-text-muted);">Gelöschte Seiten bleiben <span x-text="trashRetentionDays"></span> Tage erhalten.</p>
            <button type="button" @click="emptyTrash" :disabled="loading || pages.length === 0" class="btn btn-quiet mt-2 w-full" style="color: var(--color-danger);">Papierkorb leeren</button>
        </div>
        <div x-show="selectedPageCount() > 0" x-cloak class="flex items-center gap-2 rounded-md px-2 py-2 text-sm" style="background: color-mix(in srgb, var(--color-accent) 10%, transparent);">
            <span class="min-w-0 flex-1"><strong x-text="selectedPageCount()"></strong> ausgewählt</span>
            <button type="button" @click="trashSelectedPages" class="icon-action icon-action-danger shrink-0" aria-label="Auswahl in den Papierkorb verschieben" title="In den Papierkorb" x-icon="trash"></button>
            <button type="button" @click="clearPageSelection" class="icon-action shrink-0" aria-label="Auswahl aufheben" x-icon="x"></button>
        </div>
    </div>
    <nav class="min-h-0 flex-1 overflow-y-auto pb-3" aria-label="Seiten">
        <div x-show="!nearbyActive">
            <template x-for="page in filteredPages()" :key="page.id">
                <div class="page-card group cursor-pointer px-4 py-3" :class="{ 'is-current font-medium': page.id === currentPageId, 'is-selected': isPageSelected(page.id) }" :draggable="!page.is_shared && activeCollection !== 'trash'" role="link" tabindex="0" :aria-selected="isPageSelected(page.id)" @click="handlePageClick(page, $event)" @keydown.enter.prevent="handlePageClick(page, $event)" @keydown.space.prevent="handlePageClick(page, $event)" @dragstart.stop="beginPageDrag(page, $event)" @dragend="endPageDrag">
                    <div class="flex items-start gap-2">
                        <span x-show="page.type === 'task'" class="mt-0.5 shrink-0" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                        <span x-show="page.type === 'log'" class="mt-0.5 shrink-0" style="color: var(--color-text-muted);" x-icon="scroll-text"></span>
                        <span x-show="page.type === 'note' && page.is_encrypted" class="mt-0.5 shrink-0" style="color: var(--color-accent);" title="Verschlüsselte Notiz" x-icon="lock"></span>
                        <span x-show="page.is_shared" class="mt-0.5 shrink-0" style="color: var(--color-accent);" x-icon="share-2"></span>
                        <a :href="pageUrl(page)" @click.prevent.stop="handlePageClick(page, $event)" class="min-w-0 flex-1 break-words leading-snug" x-text="page.title"></a>
                        <button x-show="!page.is_shared && activeCollection !== 'trash'" @click.stop="toggleFavorite(page)" class="icon-action shrink-0 p-1" :class="page.is_favorite ? 'is-favorite' : ''" :aria-pressed="page.is_favorite" aria-label="Favorit umschalten" x-icon="star"></button>
                    </div>
                    <p class="ml-6 mt-1 line-clamp-2 text-xs" style="color: var(--color-text-muted);" x-text="pageSummary(page)"></p>
                    <div x-show="activeCollection !== 'trash'" class="ml-6 mt-2 flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-[11px] font-normal" style="color: var(--color-text-muted);" x-text="pageMeta(page)"></span>
                        <button x-show="!page.is_shared" @click.stop="remove(page)" class="icon-action icon-action-danger shrink-0 p-1" aria-label="Seite löschen" x-icon="trash"></button>
                        <button x-show="page.is_shared" @click.stop="leave(page)" class="icon-action icon-action-danger shrink-0 p-1" aria-label="Freigabe verlassen" x-icon="log-out"></button>
                    </div>
                    <div x-show="activeCollection === 'trash'" x-cloak class="ml-6 mt-2 flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-[11px]" style="color: var(--color-text-muted);" x-text="trashRemainingLabel(page)"></span>
                        <button type="button" @click.stop="restorePage(page)" class="btn btn-quiet shrink-0 px-2 py-1 text-xs">Wiederherstellen</button>
                        <button type="button" @click.stop="purgePage(page)" class="icon-action icon-action-danger shrink-0 p-1" aria-label="Endgültig löschen" title="Endgültig löschen" x-icon="trash"></button>
                    </div>
                </div>
            </template>
            <?php /* Nur „Alle Notizen" wächst schrittweise nach - die anderen Sammlungen
                     laden ohnehin vollständig (siehe filteredPages). */ ?>
            <div x-ref="recentSentinel" x-cloak x-show="activeCollection === 'all' && searchQuery.trim() === '' && hasMoreRecentPages()" class="px-4 pt-2">
                <button type="button" @click="loadMoreRecentPages" class="btn btn-quiet w-full">Weitere Notizen laden</button>
            </div>
            <p x-show="searchLoading" class="px-4 py-6 text-sm" style="color: var(--color-text-muted);">Suche läuft…</p>
            <p x-show="trashError" x-cloak class="px-4 py-6 text-sm" style="color: var(--color-danger);" x-text="trashError"></p>
            <p x-show="!loading && !searchLoading && !trashError && filteredPages().length === 0" class="px-4 py-6 text-sm" style="color: var(--color-text-muted);" x-text="activeCollection === 'trash' ? 'Der Papierkorb ist leer.' : 'Noch keine Seiten.'"></p>
        </div>
        <div x-show="nearbyActive" x-cloak>
            <?php include __DIR__ . '/nearby_results.php'; ?>
        </div>
    </nav>
    <?php include __DIR__ . '/nearby_search_dialog.php'; ?>
</aside>
