<div class="page-canvas page-content-canvas mx-auto px-4 pb-16 pt-2 sm:px-10 md:px-6 md:pt-5" x-data="taskBoard" data-page-id="<?= (int) $page['id'] ?>" data-page-title="<?= e((string) $page['title']) ?>" data-page-can-edit="<?= !empty($page['can_edit']) ? '1' : '0' ?>" data-page-lat="<?= e((string) ($page['location_lat'] ?? '')) ?>" data-page-lon="<?= e((string) ($page['location_lon'] ?? '')) ?>" data-page-accuracy="<?= e((string) ($page['location_accuracy'] ?? '')) ?>" data-page-address="<?= e((string) ($page['location_label'] ?? '')) ?>" data-page-is-shared="<?= !empty($page['is_shared']) ? '1' : '0' ?>">
    <div class="page-toolbar flex items-center gap-2">
        <?php /* Rückweg zur Seitenauswahl - dieselbe Ebene, die mobil auch das
                 Wischen von links nach rechts erreicht (siehe workspaceShell). */ ?>
        <button type="button" @click="goBack()" class="icon-action flex shrink-0 items-center border p-2 md:hidden" style="border-color: var(--color-border);" title="Zurück zur Seitenauswahl" aria-label="Zurück zur Seitenauswahl" x-icon="chevron-left"></button>
        <?php /* Der Seitentitel steht mobil ohnehin direkt darunter als Überschrift -
                 der Breadcrumb kostet dort nur Höhe und entfällt deshalb. Die
                 Freigabe-Anzeige und die Aktionen bleiben sichtbar. Darüber kürzt
                 er mit Auslassungspunkten, statt umzubrechen (siehe page_note.php). */ ?>
        <span class="hidden min-w-0 flex-1 items-center gap-2 md:flex">
            <?php $nbIcon = !empty($page['is_shared']) ? 'share-2' : ($page['notebook_icon'] ?? 'book-open'); ?>
            <?php $nbColor = !empty($page['is_shared']) ? 'var(--color-text-muted)' : ($page['notebook_color'] ?? 'var(--color-text-muted)'); ?>
            <span class="shrink-0" style="color: <?= e($nbColor) ?>;" x-icon="<?= e($nbIcon) ?>"></span>
            <span class="min-w-0 truncate"><?= e(!empty($page['is_shared']) ? 'Geteilt' : (string) ($page['notebook_name'] ?? 'Nicht zugewiesen')) ?></span>
            <span class="shrink-0" style="color: var(--color-text-muted);" x-icon="chevron-right"></span>
            <span class="min-w-0 truncate" x-text="pageTitle"></span>
        </span>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <span x-show="isShared" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>
                Geteilt · <span x-text="permissionLabel()"></span>
            </span>
            <span x-show="ownedAndShared()" x-cloak class="inline-flex items-center gap-1.5 text-sm" style="color: var(--color-accent);">
                <span x-icon="share-2"></span>Geteilt
            </span>
            <?php include __DIR__ . '/partials/page_writers.php'; ?>
            <button x-show="!isShared && canEditPage" @click="openShareDialog" class="icon-action flex items-center gap-1.5 border p-2 text-sm font-medium lg:px-3 lg:py-1.5" style="border-color: var(--color-border);" title="Seite teilen" aria-label="Seite teilen">
                <span x-icon="share-2"></span><span class="hidden lg:inline">Teilen</span>
            </button>
        </div>
    </div>
    <div class="pt-4 md:pt-10">
    <div class="mb-8 flex min-w-0 items-center gap-3 sm:mb-14">
        <span class="shrink-0" style="color: var(--color-text);" x-icon="list-todo"></span>
        <div class="min-w-0 flex-1">
            <h1 x-show="!editingPageTitle" @click="startEditingPageTitle" class="cursor-text text-4xl font-semibold tracking-tight sm:text-5xl" title="Titel bearbeiten" x-text="pageTitle"></h1>
            <input x-show="editingPageTitle" x-cloak x-ref="titleInput" x-model="pageTitle" @blur="savePageTitle" @keydown.enter.prevent="savePageTitle" @keydown.escape.prevent="cancelPageTitleEdit" class="page-title-input w-full min-w-72 text-4xl font-semibold tracking-tight sm:text-5xl">
             <?php include __DIR__ . '/partials/page_location.php'; ?>
             <?php include __DIR__ . '/partials/shared_page_meta.php'; ?>
        </div>
    </div>

    <p x-show="offlineNotice" x-cloak x-text="offlineNotice" class="mb-6 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); color: var(--color-text-muted);"></p>

    <?php /* Kapitelauswahl: auf dem Desktop als Reiter, auf dem Handy als
             Dropdown (dort ist kein Platz für Reiter nebeneinander). In beiden
             Fällen ist immer nur ein Kapitel aktiv, dessen Aufgaben darunter
             erscheinen - das "..."-Menü daneben legt neue Kapitel an und
             verwaltet das gerade gewählte (FR-TASK-16). */ ?>
    <div x-show="categories.length > 0" class="mb-6">
        <div class="hidden items-center gap-1 border-b md:flex" style="border-color: var(--color-border);">
            <?php /* `overflow-x-auto` allein setzt laut CSS-Spezifikation auch
                     `overflow-y` implizit auf `auto`, sobald der Inhalt nur
                     minimal höher als der Container ist - das zeigte sonst eine
                     unnötige vertikale Scrollbar neben dem "..."-Menü. */ ?>
            <div class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto overflow-y-hidden">
                <template x-for="category in categories" :key="category.id">
                    <button
                        type="button"
                        @click="selectCategory(category)"
                        class="tab-button"
                        :class="isSelectedCategory(category) ? 'is-active' : ''"
                        x-text="category.name"
                    ></button>
                </template>
            </div>
            <div x-show="canEditPage" class="relative mb-1 shrink-0">
                <button
                    type="button"
                    @click="toggleCategoryMenu(selectedCategoryId)"
                    class="icon-action"
                    :aria-expanded="isCategoryMenuOpen(selectedCategoryId)"
                    title="Kapitel-Menü"
                    aria-label="Kapitel-Menü"
                    x-icon="more-horizontal"
                ></button>
                <div
                    x-show="isCategoryMenuOpen(selectedCategoryId)"
                    x-cloak
                    @click.outside="closeCategoryMenu"
                    @keydown.escape.window="closeCategoryMenu"
                    class="absolute right-0 top-10 z-40 w-56 rounded-lg border p-1"
                    style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);"
                >
                    <button type="button" @click="menuAddCategory" class="menu-item">Kapitel anlegen</button>
                    <button type="button" x-show="hasSelectedCategory()" @click="menuRenameCategory(selectedCategory())" class="menu-item">Kapitel umbenennen</button>
                    <button type="button" x-show="hasSelectedCategory()" @click="menuImportTasks(selectedCategory())" class="menu-item">Aufgaben einfügen</button>
                    <button type="button" x-show="hasSelectedCategory()" @click="menuDeleteCategory(selectedCategory())" class="menu-item menu-item-danger">Kapitel löschen</button>
                </div>
            </div>
        </div>

        <?php /* Beschriftung nur für Screenreader - das Dropdown erklärt sich visuell
                 selbst und der Platz wird auf dem Handy gebraucht. */ ?>
        <div class="md:hidden">
            <label for="task-category-select" class="sr-only">Kapitel</label>
            <div class="flex items-center gap-2">
                <select
                    id="task-category-select"
                    x-model="selectedCategoryId"
                    @change="persistSelectedCategory"
                    class="min-w-0 flex-1 rounded-lg border px-3 py-3 text-base font-medium"
                    style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);"
                >
                    <template x-for="category in categories" :key="category.id">
                        <option :value="category.id" x-text="category.name"></option>
                    </template>
                </select>
                <div x-show="canEditPage" class="relative shrink-0">
                    <button
                        type="button"
                        @click="toggleCategoryMenu(selectedCategoryId)"
                        class="icon-action flex size-12 items-center justify-center rounded-lg border"
                        style="border-color: var(--color-border);"
                        :aria-expanded="isCategoryMenuOpen(selectedCategoryId)"
                        title="Kapitel-Menü"
                        aria-label="Kapitel-Menü"
                        x-icon="more-horizontal"
                    ></button>
                    <div
                        x-show="isCategoryMenuOpen(selectedCategoryId)"
                        x-cloak
                        @click.outside="closeCategoryMenu"
                        @keydown.escape.window="closeCategoryMenu"
                        class="absolute right-0 top-14 z-40 w-56 rounded-lg border p-1"
                        style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);"
                    >
                        <button type="button" @click="menuAddCategory" class="menu-item">Kapitel anlegen</button>
                        <button type="button" x-show="hasSelectedCategory()" @click="menuRenameCategory(selectedCategory())" class="menu-item">Kapitel umbenennen</button>
                        <button type="button" x-show="hasSelectedCategory()" @click="menuImportTasks(selectedCategory())" class="menu-item">Aufgaben einfügen</button>
                        <button type="button" x-show="hasSelectedCategory()" @click="menuDeleteCategory(selectedCategory())" class="menu-item menu-item-danger">Kapitel löschen</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php /* Leerzustand: großer Plus-Kreis statt eines kleinen Textlinks, gut
             sichtbar mittig - eine neue Task-Seite hat bewusst keine
             vorbelegten Kapitel mehr. */ ?>
    <div x-show="!loading && categories.length === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed px-6 py-20 text-center" style="border-color: var(--color-border);">
        <button
            type="button"
            x-show="canEditPage"
            @click="openCategoryDialog"
            class="icon-action flex size-16 items-center justify-center rounded-full border-2 border-dashed"
            style="border-color: var(--color-border); color: var(--color-text-muted);"
            aria-label="Kapitel anlegen"
            x-icon="plus:size-8"
        ></button>
        <div>
            <p class="text-lg font-medium">Noch keine Kapitel</p>
            <button type="button" x-show="canEditPage" @click="openCategoryDialog" class="mt-1 text-sm font-medium" style="color: var(--color-accent);">Kapitel anlegen</button>
            <p x-show="!canEditPage" class="mt-1 text-sm" style="color: var(--color-text-muted);">Diese Seite hat noch keine Kapitel.</p>
        </div>
    </div>

    <div class="max-w-4xl space-y-12">
        <template x-for="category in visibleCategories()" :key="category.id">
            <section class="pb-2">
                <header class="flex items-start justify-between gap-4">
                    <p class="text-sm" style="color: var(--color-text-muted);">
                        <span x-text="openTaskCount(category)"></span> offen
                        <span aria-hidden="true">·</span>
                        <span x-text="completedTaskCount(category)"></span> erledigt
                        <span aria-hidden="true">·</span>
                        <span x-text="category.tasks.length"></span> gesamt
                    </p>
                    <div class="flex shrink-0 items-center gap-1">
                        <button @click="toggleCompletedTasks(category)" class="icon-action" :aria-pressed="areCompletedTasksHidden(category)" :aria-label="areCompletedTasksHidden(category) ? 'Erledigte Aufgaben einblenden' : 'Erledigte Aufgaben ausblenden'" :title="areCompletedTasksHidden(category) ? 'Erledigte Aufgaben einblenden' : 'Erledigte Aufgaben ausblenden'">
                            <span x-show="areCompletedTasksHidden(category)" x-icon="eye"></span>
                            <span x-show="!areCompletedTasksHidden(category)" x-icon="eye-off"></span>
                        </button>
                        <?php /* Zeigt nur Aufgaben, deren Verantwortlicher der eigene Name ist
                                 (FR-TASK-22). Nur Symbol mit Tooltip - der Kopf ist schmal. */ ?>
                        <button
                            x-show="canFilterMine()"
                            @click="toggleOnlyMine(category)"
                            class="icon-action"
                            :class="isOnlyMine(category) ? 'is-filter-active' : ''"
                            :aria-pressed="isOnlyMine(category)"
                            :aria-label="isOnlyMine(category) ? 'Alle Aufgaben anzeigen' : 'Nur meine Aufgaben anzeigen'"
                            :title="isOnlyMine(category) ? 'Alle Aufgaben anzeigen' : 'Nur meine Aufgaben anzeigen'"
                            x-icon="user"
                        ></button>
                    </div>
                </header>

                <ul class="task-list mt-4 border-y" style="border-color: var(--color-border);">
                    <template x-for="task in visibleTasks(category)" :key="task.id">
                        <li class="group flex items-center gap-2 border-t px-2 py-2 text-base sm:px-3" style="border-color: color-mix(in srgb, var(--color-border) 70%, transparent);" :class="task.is_done ? 'opacity-60' : ''">
                            <input type="checkbox" :checked="task.is_done" @change="toggleDone(task)" :disabled="!canEditPage" class="h-5 w-5 shrink-0 sm:h-4 sm:w-4">
                            <button @click="openTask(task)" class="task-title min-w-0 flex-1 text-left" :class="task.is_done ? 'line-through' : ''" x-text="task.title"></button>
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
                            <?php /* Ohne Hover gäbe es auf Touchgeräten keinen Weg zu Bearbeiten
                                     und Löschen - dort stehen die Schalter deshalb dauerhaft. */ ?>
                            <button x-show="canEditPage" @click="openTask(task)" class="icon-action shrink-0 md:opacity-0 md:group-hover:opacity-100 md:focus:opacity-100" title="Task bearbeiten" aria-label="Task bearbeiten" x-icon="pencil"></button>
                            <button x-show="canEditPage" @click="deleteTask(task)" class="icon-action icon-action-danger shrink-0 md:opacity-0 md:group-hover:opacity-100 md:focus:opacity-100" title="Task löschen" aria-label="Task löschen" x-icon="trash"></button>
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
            </section>
        </template>
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
        <form x-show="activeTask" @submit.prevent="saveTask" @keydown.escape.window="closeTask()" @keydown.enter="handleTaskEditorEnter($event)" class="w-full max-w-xl rounded-2xl border p-6 space-y-4 sm:p-8" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <template x-if="activeTask">
                <div class="space-y-3">
                    <input x-model="activeTask.title" :readonly="!canEditPage" class="w-full rounded-lg border px-4 py-3 text-base font-medium" style="border-color: var(--color-border);">
                    <textarea x-model="activeTask.description" :readonly="!canEditPage" rows="5" placeholder="Beschreibung" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);"></textarea>
                    <?php /* Ist die Seite geteilt, stehen die Personen mit Zugriff zur Auswahl;
                             Freitext bleibt über „Andere Person…“ möglich (FR-TASK-21). */ ?>
                    <select
                        x-show="hasCollaboratorChoices()"
                        :value="responsibleSelectValue()"
                        @change="onResponsibleSelect"
                        :disabled="!canEditPage"
                        aria-label="Verantwortlich"
                        class="w-full rounded-lg border px-4 py-3 text-base"
                        style="border-color: var(--color-border); background: var(--color-bg); color: var(--color-text);"
                    >
                        <option value="">Niemand zugewiesen</option>
                        <template x-for="person in collaborators" :key="person.id">
                            <option :value="person.name" x-text="collaboratorLabel(person)"></option>
                        </template>
                        <option value="__free__">Andere Person…</option>
                    </select>
                    <input x-show="!hasCollaboratorChoices() || responsibleFreeText" x-model="activeTask.responsible" :readonly="!canEditPage" placeholder="Verantwortlich" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);">
                    <input x-model="activeTask.link" :readonly="!canEditPage" placeholder="https://…" class="w-full rounded-lg border px-4 py-3 text-base" style="border-color: var(--color-border);">
                    <label class="flex cursor-pointer items-center gap-3 text-base">
                        <input type="checkbox" x-model="activeTask.is_done" :disabled="!canEditPage" class="h-6 w-6 shrink-0"> Erledigt
                    </label>
                    <div x-show="taskConflict" x-cloak class="rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);" role="alert">
                        <p>Dieser Task wurde zwischenzeitlich von jemand anderem geändert.</p>
                        <p class="mt-2">
                            <button type="button" @click="useTheirVersion" class="font-medium underline">Fremde Änderungen laden</button>
                            <span style="color: var(--color-text-muted);"> oder erneut „Speichern“, um deine Version zu übernehmen.</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 pt-2">
                        <?php /* Löschen auch aus dem Detaildialog heraus - auf dem Handy ist
                                 das der bequemste Weg (FR-TASK-08). */ ?>
                        <button
                            x-show="canEditPage"
                            type="button"
                            @click="deleteActiveTask"
                            class="icon-action icon-action-danger flex items-center gap-1.5 px-3 py-2 text-base font-medium"
                            :disabled="savingTask"
                        >
                            <span x-icon="trash"></span>Löschen
                        </button>
                        <button type="button" @click="closeTask()" class="ml-auto px-3 py-2 text-base" style="color: var(--color-text-muted);">Abbrechen</button>
                        <button x-show="canEditPage" type="submit" class="rounded-lg px-4 py-2.5 text-base font-medium text-white" style="background: var(--color-accent);" :disabled="savingTask" x-text="savingTask ? 'Speichert…' : 'Speichern'"></button>
                    </div>
                </div>
            </template>
        </form>
    </div>

    <?php include __DIR__ . '/partials/page_location_dialog.php'; ?>
</div>
