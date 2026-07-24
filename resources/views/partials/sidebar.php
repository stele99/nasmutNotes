<aside
    x-data="pageList"
    class="fixed inset-y-0 left-0 z-40 flex h-screen w-72 -translate-x-full flex-col border-r transition-transform duration-200 md:sticky md:top-0 md:translate-x-0"
    :class="{ 'translate-x-0': sidebarOpen }"
    style="border-color: var(--color-border); background-color: var(--color-bg-subtle);"
>
    <div class="flex items-center gap-2 px-4 pb-3 pt-5 text-[15px] font-semibold">
        <span class="flex size-6 items-center justify-center rounded-md" style="background: var(--color-bg);" x-icon="home"></span>
        <span>Notizen &amp; Tasks</span>
        <span class="ml-auto" style="color: var(--color-text-muted);" x-icon="more-horizontal"></span>
    </div>

    <div class="space-y-0.5 px-3 pb-5 text-sm" style="color: var(--color-text-muted);">
        <a href="/app" @click="sidebarOpen = false" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-white/70">
            <span x-icon="home"></span>
            Übersicht
        </a>
        <form @submit.prevent="search" class="flex items-center gap-2 rounded-md border bg-white px-2 py-1.5" style="border-color: var(--color-border);">
            <span style="color: var(--color-text-muted);" x-icon="search"></span>
            <input x-model="searchQuery" @input="if (!searchQuery) searchResults = []" type="search" placeholder="Suchen und Enter drücken…" class="sidebar-search min-w-0 flex-1 bg-transparent text-sm outline-none">
            <button type="submit" class="icon-action" :disabled="searchLoading" aria-label="Suchen" x-icon="search"></button>
        </form>
    </div>

    <div class="flex items-center gap-1 px-3 pb-3">
        <button @click="createPage('note')" class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-2 text-sm font-medium hover:bg-white" style="background: var(--color-bg);">
            <span x-icon="plus"></span>
            Notiz
        </button>
        <button @click="createPage('task')" class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-2 text-sm font-medium hover:bg-white" style="background: var(--color-bg);">
            <span x-icon="plus"></span>
            Aufgaben
        </button>
    </div>

    <div class="px-5 pb-2 text-xs font-medium" style="color: var(--color-text-muted);">Privat</div>
    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
        <template x-for="page in filteredPages()" :key="page.id">
            <div
                class="group flex items-center gap-1 rounded-md px-2 py-1.5 text-sm"
                :class="page.id === currentPageId ? 'font-medium' : ''"
                :style="page.id === currentPageId ? 'background-color: color-mix(in srgb, var(--color-text) 8%, transparent);' : ''"
            >
                <span x-show="page.type === 'note'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="file-text"></span>
                <span x-show="page.type === 'task'" class="shrink-0" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
                <a :href="pageUrl(page)" @click="sidebarOpen = false" class="min-w-0 flex-1 truncate" x-text="page.title"></a>
                <button @click="toggleFavorite(page)" class="opacity-0 group-hover:opacity-100 focus:opacity-100" :class="page.is_favorite ? 'opacity-100' : ''" aria-label="Favorit umschalten" x-icon="star"></button>
                <button @click.stop="rename(page)" class="opacity-0 group-hover:opacity-100 focus:opacity-100" aria-label="Seite umbenennen" x-icon="pencil"></button>
                <button @click.stop="remove(page)" class="opacity-0 group-hover:opacity-100 focus:opacity-100" style="color: var(--color-danger);" aria-label="Seite löschen" x-icon="trash"></button>
            </div>
        </template>
        <p x-show="searchLoading" class="px-2 py-6 text-sm" style="color: var(--color-text-muted);">Suche läuft…</p>
        <p x-show="!loading && !searchLoading && filteredPages().length === 0" class="px-2 py-6 text-sm" style="color: var(--color-text-muted);">
            <span x-text="searchQuery ? 'Keine passende Seite gefunden.' : 'Noch keine Seiten.'"></span>
        </p>
    </nav>

    <div class="mx-3 mb-3 flex items-center gap-2 border-t px-2 pt-3 text-sm" style="border-color: var(--color-border); color: var(--color-text-muted);">
        <?php if (!empty($isAdmin)): ?>
            <a href="/admin">Admin</a>
        <?php endif; ?>
        <button @click="logout()" class="ml-auto">Abmelden</button>
    </div>
</aside>
