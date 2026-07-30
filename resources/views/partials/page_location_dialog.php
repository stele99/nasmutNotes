<?php /* Gemeinsame Standortauswahl für Notiz, Aufgabenliste und Logbuch. */ ?>
<div
    x-show="locationDialogOpen"
    x-cloak
    <?php /* Über den Eintragsdialog des Logbuchs (z-100), aus dem die
             Ortsspalte diese Auswahl heraus öffnet. */ ?>
    class="fixed inset-0 z-[110] flex items-center justify-center p-3 sm:p-5"
    style="background-color: rgb(0 0 0 / 0.45);"
    @click.self="closeLocationDialog"
    @keydown.escape.window="closeLocationDialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="location-dialog-title"
>
    <form @submit.prevent="applyLocationInput" class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-lg flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
            <div>
                <h2 id="location-dialog-title" class="text-xl font-semibold">Standort</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Adresse suchen, auf die Karte tippen oder den Marker ziehen.</p>
            </div>
            <button type="button" @click="closeLocationDialog" :disabled="locationBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
            <label for="location-search-input" class="block text-sm font-medium">Ort oder Adresse suchen</label>
            <div class="mt-2 flex gap-2">
                <input id="location-search-input" x-model="locationSearchQuery" @keydown.enter.prevent="searchLocationAddress" :disabled="locationBusy || locationSearching" type="search" placeholder="z. B. Marienplatz München" class="min-w-0 flex-1 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <button type="button" @click="searchLocationAddress" :disabled="locationBusy || locationSearching" class="btn btn-secondary" x-text="locationSearching ? 'Sucht…' : 'Suchen'"></button>
            </div>

            <div x-show="locationSearchResults.length > 0" x-cloak class="mt-2 overflow-hidden rounded-md border" style="border-color: var(--color-border);">
                <template x-for="result in locationSearchResults" :key="locationSearchResultKey(result)">
                    <button type="button" @click="selectLocationSearchResult(result)" class="block w-full border-b px-3 py-2 text-left text-sm last:border-b-0 hover:opacity-70" style="border-color: var(--color-border);" x-text="result.label"></button>
                </template>
            </div>

            <div x-ref="locationMap" class="mt-4 h-52 w-full overflow-hidden rounded-lg border sm:h-60" style="border-color: var(--color-border);" aria-label="Kartenauswahl"></div>

            <button type="button" @click="useCurrentLocation" :disabled="locationBusy || locationLocating || !locationSupported" class="btn btn-secondary mt-4 w-full">
                <span x-icon="map-pin"></span><span x-text="locationLocating ? 'Einen Moment…' : 'Aktuellen Standort auswählen'"></span>
            </button>
            <p x-show="!locationSupported" x-cloak class="mt-2 text-xs" style="color: var(--color-danger);">Dieser Browser bietet keine Ortung an.</p>

            <label for="location-input" class="mt-5 block text-sm font-medium">Koordinaten oder Kartenlink</label>
            <input id="location-input" x-model="locationInput" :disabled="locationBusy" type="text" placeholder="48.7758, 9.1829" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Die Auswahl wird erst mit „Übernehmen“ gespeichert.</p>

            <p x-show="locationError" x-cloak x-text="locationError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
        </div>

        <div class="flex flex-wrap justify-end gap-2 border-t px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
            <?php /* „Entfernen" löscht den Aufnahmeort der Seite - bei einer
                     Ortsspalte wäre das das falsche Ziel. */ ?>
            <button x-show="pageLocation && locationPickerTarget === null" x-cloak type="button" @click="removeLocation" :disabled="locationBusy" class="btn btn-quiet mr-auto" style="color: var(--color-danger);">Entfernen</button>
            <button type="button" @click="closeLocationDialog" :disabled="locationBusy" class="btn btn-quiet">Abbrechen</button>
            <button type="submit" :disabled="locationBusy || !locationInput.trim()" class="btn btn-primary" x-text="locationBusy ? 'Speichert…' : 'Übernehmen'"></button>
        </div>
    </form>
</div>
