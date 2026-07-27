<div
    x-show="notebookDialogOpen"
    x-cloak
    class="fixed inset-0 z-[80] flex items-center justify-center p-5"
    style="background-color: rgb(0 0 0 / 0.4);"
    role="dialog"
    aria-modal="true"
    aria-labelledby="notebook-dialog-title"
    @click.self="closeNotebookDialog"
    @keydown.escape.window="closeNotebookDialog"
>
    <form @submit.prevent="saveNotebook" class="w-full max-w-sm rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="notebook-dialog-title" class="text-xl font-semibold" x-text="notebookDialogMode === 'rename' ? 'Notizbuch umbenennen' : 'Notizbuch anlegen'"></h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);" x-text="notebookDialogMode === 'rename' ? 'Gib dem Notizbuch einen neuen Namen.' : 'Organisiere Notizen und Aufgaben in einem gemeinsamen Buch.'"></p>
            </div>
            <button type="button" @click="closeNotebookDialog" :disabled="notebookSaving" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>
        <label for="notebook-name" class="mt-5 block text-sm font-medium">Name</label>
        <input x-ref="notebookName" id="notebook-name" x-model="notebookName" :disabled="notebookSaving" maxlength="100" autocomplete="off" class="mt-2 w-full rounded-md border px-3 py-2" style="border-color: var(--color-border); background: var(--color-bg);" placeholder="Zum Beispiel Projekte">
        <fieldset class="mt-5">
            <legend class="text-sm font-medium">Farbe</legend>
            <div class="mt-2 flex flex-wrap gap-2">
                <template x-for="color in notebookColors" :key="color.value">
                    <button type="button" @click="selectNotebookColor(color.value)" :aria-label="color.label" :aria-pressed="isNotebookColorSelected(color.value)" class="notebook-color-choice" :class="isNotebookColorSelected(color.value) ? 'is-selected' : ''" :style="notebookColorStyle(color.value)"></button>
                </template>
            </div>
        </fieldset>
        <fieldset class="mt-5">
            <legend class="text-sm font-medium">Symbol</legend>
            <div class="mt-2 grid grid-cols-6 gap-2">
                <button type="button" @click="selectNotebookIcon('book-open')" aria-label="Notizbuch" :aria-pressed="isNotebookIconSelected('book-open')" class="notebook-icon-choice" :class="isNotebookIconSelected('book-open') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="book-open:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('folder')" aria-label="Ordner" :aria-pressed="isNotebookIconSelected('folder')" class="notebook-icon-choice" :class="isNotebookIconSelected('folder') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="folder:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('briefcase')" aria-label="Arbeit" :aria-pressed="isNotebookIconSelected('briefcase')" class="notebook-icon-choice" :class="isNotebookIconSelected('briefcase') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="briefcase:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('house')" aria-label="Zuhause" :aria-pressed="isNotebookIconSelected('house')" class="notebook-icon-choice" :class="isNotebookIconSelected('house') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="house:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('plane')" aria-label="Reisen" :aria-pressed="isNotebookIconSelected('plane')" class="notebook-icon-choice" :class="isNotebookIconSelected('plane') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="plane:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('heart')" aria-label="Persönlich" :aria-pressed="isNotebookIconSelected('heart')" class="notebook-icon-choice" :class="isNotebookIconSelected('heart') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="heart:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('lightbulb')" aria-label="Ideen" :aria-pressed="isNotebookIconSelected('lightbulb')" class="notebook-icon-choice" :class="isNotebookIconSelected('lightbulb') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="lightbulb:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('laptop')" aria-label="Technik" :aria-pressed="isNotebookIconSelected('laptop')" class="notebook-icon-choice" :class="isNotebookIconSelected('laptop') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="laptop:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('wrench')" aria-label="Projekte" :aria-pressed="isNotebookIconSelected('wrench')" class="notebook-icon-choice" :class="isNotebookIconSelected('wrench') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="wrench:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('utensils')" aria-label="Rezepte" :aria-pressed="isNotebookIconSelected('utensils')" class="notebook-icon-choice" :class="isNotebookIconSelected('utensils') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="utensils:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('graduation-cap')" aria-label="Lernen" :aria-pressed="isNotebookIconSelected('graduation-cap')" class="notebook-icon-choice" :class="isNotebookIconSelected('graduation-cap') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="graduation-cap:size-5"></span></button>
                <button type="button" @click="selectNotebookIcon('star')" aria-label="Wichtig" :aria-pressed="isNotebookIconSelected('star')" class="notebook-icon-choice" :class="isNotebookIconSelected('star') ? 'is-selected' : ''" :style="selectedNotebookIconStyle()"><span x-icon="star:size-5"></span></button>
            </div>
        </fieldset>
        <p x-show="notebookError" x-cloak x-text="notebookError" class="mt-2 text-sm" style="color: var(--color-danger);" role="alert"></p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="closeNotebookDialog" :disabled="notebookSaving" class="btn btn-quiet">Abbrechen</button>
            <button type="submit" :disabled="notebookSaving" class="btn btn-primary" x-text="notebookSaving ? (notebookDialogMode === 'rename' ? 'Speichere…' : 'Lege an…') : (notebookDialogMode === 'rename' ? 'Umbenennen' : 'Notizbuch anlegen')"></button>
        </div>
    </form>
</div>
