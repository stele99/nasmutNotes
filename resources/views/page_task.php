<div class="page-canvas mx-auto px-6 pb-16 pt-20 sm:px-10 md:pt-14" x-data="taskBoard">
    <div class="page-toolbar flex items-center gap-2">
        <span style="color: var(--color-text-muted);" x-icon="list-todo"></span>
        Aufgabenliste
        <span style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
        <span class="truncate" x-text="pageTitle"></span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <span x-show="isShared" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>
                Geteilt · <span x-text="permissionLabel()"></span>
            </span>
            <button x-show="!isShared" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border px-3 py-1.5 text-sm font-medium" style="border-color: var(--color-border);" title="Seite teilen" aria-label="Seite teilen">
                <span x-icon="share-2"></span>Teilen
            </button>
        </div>
    </div>
    <div class="pt-10">
    <div class="mb-14 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="flex size-12 items-center justify-center rounded-xl" style="background: color-mix(in srgb, var(--color-accent) 12%, transparent); color: var(--color-accent);" x-icon="list-todo"></div>
            <div class="mt-5 min-w-0">
                <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text text-4xl font-semibold tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
                <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="w-full min-w-72 rounded-md border px-3 py-2 text-3xl font-semibold tracking-tight sm:text-4xl" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button x-show="canEditPage" @click="openCategoryDialog" class="icon-action flex shrink-0 items-center gap-1.5 border px-3 py-2 text-sm font-medium" style="border-color: var(--color-border);" title="Kapitel anlegen" aria-label="Kapitel anlegen">
                <span x-icon="plus"></span>Kapitel
            </button>
        </div>
    </div>

    <div class="max-w-4xl space-y-12">
        <template x-for="category in categories" :key="category.id">
            <section class="pb-2">
                <header class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <button
                                @click="toggleCategory(category)"
                                class="icon-action shrink-0"
                                :aria-expanded="!isCategoryCollapsed(category)"
                                :aria-label="isCategoryCollapsed(category) ? 'Kategorie aufklappen' : 'Kategorie zuklappen'"
                                title="Kategorie auf- oder zuklappen"
                            ><span x-icon="chevron-right" class="transition-transform duration-150" :class="isCategoryCollapsed(category) ? '' : 'rotate-90'"></span></button>
                            <span style="color: var(--color-text-muted);" x-icon="folder-kanban"></span>
                            <button
                                x-show="canEditPage"
                                @click="renameCategory(category)"
                                class="text-left text-xl font-semibold tracking-tight"
                                title="Kapitel umbenennen"
                                x-text="category.name"
                            ></button>
                            <span
                                x-show="!canEditPage"
                                class="text-xl font-semibold tracking-tight"
                                x-text="category.name"
                            ></span>
                        </div>
                        <p class="mt-1 pl-7 text-sm" style="color: var(--color-text-muted);">
                            <span x-text="openTaskCount(category)"></span> offen
                            <span aria-hidden="true">·</span>
                            <span x-text="completedTaskCount(category)"></span> erledigt
                            <span aria-hidden="true">·</span>
                            <span x-text="category.tasks.length"></span> gesamt
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button x-show="canEditPage" @click="openImportDialog(category)" class="icon-action flex items-center gap-1.5 border px-2 py-1.5 text-sm" style="border-color: var(--color-border);" title="Aufgaben einfügen" aria-label="Aufgaben einfügen">
                            <span x-icon="clipboard-paste"></span>Einfügen
                        </button>
                        <button x-show="canEditPage" @click="deleteCategory(category)" class="icon-action icon-action-danger" title="Kapitel löschen" aria-label="Kapitel löschen" x-icon="trash"></button>
                    </div>
                </header>

                <div x-show="!isCategoryCollapsed(category)">
                <ul class="task-list mt-4 border-y" style="border-color: var(--color-border);">
                    <template x-for="task in category.tasks" :key="task.id">
                        <li class="group flex items-center gap-2 border-t px-2 py-2 text-base sm:px-3" style="border-color: color-mix(in srgb, var(--color-border) 70%, transparent);" :class="task.is_done ? 'opacity-60' : ''">
                            <input type="checkbox" :checked="task.is_done" @change="toggleDone(task)" :disabled="!canEditPage" class="h-4 w-4 shrink-0">
                            <button @click="openTask(task)" class="min-w-0 flex-1 text-left" :class="task.is_done ? 'line-through' : ''" x-text="task.title"></button>
                            <span x-show="task.responsible" class="hidden text-sm sm:inline" style="color: var(--color-text-muted);" x-text="task.responsible"></span>
                            <a
                                x-show="task.link"
                                :href="task.link"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-sm"
                                style="color: var(--color-text-muted);"
                                title="Link öffnen"
                            ><span x-icon="chevron-right"></span></a>
                            <button x-show="canEditPage" @click="openTask(task)" class="icon-action opacity-0 group-hover:opacity-100 focus:opacity-100" title="Task bearbeiten" aria-label="Task bearbeiten" x-icon="pencil"></button>
                            <button x-show="canEditPage" @click="deleteTask(task)" class="icon-action icon-action-danger opacity-0 group-hover:opacity-100 focus:opacity-100" title="Task löschen" aria-label="Task löschen" x-icon="trash"></button>
                        </li>
                    </template>
                </ul>

                <form x-show="canEditPage" @submit.prevent="addTask(category, $event)" class="mt-4 flex items-center gap-3 rounded-lg border px-4 py-3" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                    <span aria-hidden="true" style="color: var(--color-text-muted);" x-icon="plus"></span>
                    <input
                        x-model="newTaskTitles[category.id]"
                        type="text"
                        :disabled="savingCategoryId === category.id"
                        placeholder="Aufgabe eingeben und Enter drücken…"
                        aria-label="Neue Aufgabe in diesem Kapitel"
                        class="min-w-0 flex-1 bg-transparent text-base outline-none"
                    >
                    <button type="submit" class="icon-action" :disabled="savingCategoryId === category.id" style="color: var(--color-accent);" title="Aufgabe hinzufügen" aria-label="Aufgabe hinzufügen" x-icon="check"></button>
                </form>
                </div>
            </section>
        </template>

        <p x-show="!loading && categories.length === 0" style="color: var(--color-text-muted);">
            Noch keine Kapitel. Lege oben ein neues an.
        </p>
    </div>
    </div>

    <div
        x-show="creatingCategory"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeCategoryDialog"
        @keydown.escape.window="closeCategoryDialog"
    >
        <form @submit.prevent="addCategory" class="w-full max-w-sm rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <h2 class="text-xl font-semibold">Neues Kapitel</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Gib dem Kapitel einen Namen.</p>
            <input x-ref="categoryName" x-model="newCategoryName" type="text" maxlength="100" placeholder="Kapitelname" class="mt-5 w-full rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="closeCategoryDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Abbrechen</button>
                <button type="submit" class="rounded-md px-4 py-2 text-sm font-medium text-white" style="background: var(--color-accent);">Anlegen</button>
            </div>
        </form>
    </div>

    <div
        x-show="importCategory"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-5"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeImportDialog"
        @keydown.escape.window="closeImportDialog"
    >
        <form @submit.prevent="importTasks" class="w-full max-w-xl rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <h2 class="text-xl font-semibold">Aufgaben einfügen</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Eine Zeile erzeugt eine Aufgabe in „<span x-text="importCategory ? importCategory.name : ''"></span>“. Leere Zeilen werden übersprungen.
            </p>
            <textarea x-ref="importText" x-model="importText" rows="10" maxlength="500000" placeholder="Aufgabe 1
Aufgabe 2
Aufgabe 3" class="mt-5 w-full resize-y rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
            <p x-show="importError" x-text="importError" class="mt-2 text-sm" style="color: var(--color-danger);"></p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="closeImportDialog" class="px-3 py-2 text-sm" style="color: var(--color-text-muted);">Abbrechen</button>
                <button type="submit" class="rounded-md px-4 py-2 text-sm font-medium text-white" style="background: var(--color-accent);" :disabled="importing" x-text="importing ? 'Füge ein…' : 'OK'"></button>
            </div>
        </form>
    </div>

    <div
        x-show="activeTask"
        x-cloak
        class="fixed inset-0 flex items-center justify-center z-50"
        style="background-color: rgb(0 0 0 / 0.4);"
        @click.self="closeTask()"
    >
        <div x-show="activeTask" class="w-full max-w-xl rounded-2xl border p-6 space-y-4 sm:p-8" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);" @keydown.escape.window="closeTask()">
            <template x-if="activeTask">
                <div class="space-y-3">
                    <input x-model="activeTask.title" :readonly="!canEditPage" class="w-full rounded-lg border px-4 py-3 text-base font-medium" style="border-color: var(--color-border);">
                    <textarea x-model="activeTask.description" :readonly="!canEditPage" rows="5" placeholder="Beschreibung" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);"></textarea>
                    <input x-model="activeTask.responsible" :readonly="!canEditPage" placeholder="Verantwortlich" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);">
                    <input x-model="activeTask.link" :readonly="!canEditPage" placeholder="https://…" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);">
                    <label class="flex items-center gap-2 text-base">
                        <input type="checkbox" x-model="activeTask.is_done" :disabled="!canEditPage"> Erledigt
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button @click="closeTask()" class="px-3 py-2 text-base" style="color: var(--color-text-muted);">Abbrechen</button>
                        <button x-show="canEditPage" @click="saveTask()" class="rounded-lg px-4 py-2.5 text-base font-medium text-white" style="background: var(--color-accent);">Speichern</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
