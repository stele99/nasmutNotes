<?php /* Standort setzen, verschieben oder entfernen (FR-NOTE-25). Ohne
         eingebundene Karte: Der aktuelle Ort kommt vom Gerät, jeder andere über
         Koordinaten oder einen kopierten Kartenlink. */ ?>
<div
    x-show="locationDialogOpen"
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center p-5"
    style="background-color: rgb(0 0 0 / 0.45);"
    @click.self="closeLocationDialog"
    @keydown.escape.window="closeLocationDialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="location-dialog-title"
>
    <form @submit.prevent="applyLocationInput" class="w-full max-w-md rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="location-dialog-title" class="text-xl font-semibold">Standort</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Wo diese Seite entstanden ist.</p>
            </div>
            <button type="button" @click="closeLocationDialog" :disabled="locationBusy" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>

        <button type="button" @click="useCurrentLocation" :disabled="locationBusy || !locationSupported" class="btn btn-secondary mt-5 w-full">
            <span x-icon="map-pin"></span><span x-text="locationBusy ? 'Einen Moment…' : 'Aktuellen Standort verwenden'"></span>
        </button>
        <p x-show="!locationSupported" x-cloak class="mt-2 text-xs" style="color: var(--color-danger);">Dieser Browser bietet keine Ortung an.</p>

        <label for="location-input" class="mt-6 block text-sm font-medium">Anderer Ort</label>
        <input id="location-input" x-model="locationInput" :disabled="locationBusy" type="text" placeholder="48.7758, 9.1829" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
        <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Koordinaten eingeben oder einen Link von OpenStreetMap bzw. Google Maps einfügen.</p>

        <p x-show="locationError" x-cloak x-text="locationError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button x-show="pageLocation" x-cloak type="button" @click="removeLocation" :disabled="locationBusy" class="btn btn-quiet mr-auto" style="color: var(--color-danger);">Entfernen</button>
            <button type="button" @click="closeLocationDialog" :disabled="locationBusy" class="btn btn-quiet">Abbrechen</button>
            <button type="submit" :disabled="locationBusy" class="btn btn-primary">Übernehmen</button>
        </div>
    </form>
</div>
