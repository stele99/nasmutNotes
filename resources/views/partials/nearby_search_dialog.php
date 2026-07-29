<?php /* Umkreissuche (FR-NOTE-27): Mittelpunkt per aktuellem Standort, Klick auf
         die Karte oder Ziehen der Markierung, dazu ein Regler für den Umkreis.
         Die Kartenkacheln kommen über den Server (siehe MapTileProxy) - der
         Browser des Nutzers spricht nie direkt mit dem Kartendienst. */ ?>
<div
    x-show="nearbyDialogOpen"
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center p-5"
    style="background-color: rgb(0 0 0 / 0.45);"
    @click.self="closeNearbyDialog"
    @keydown.escape.window="closeNearbyDialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="nearby-dialog-title"
>
    <div class="w-full max-w-md rounded-xl border p-6" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="nearby-dialog-title" class="text-xl font-semibold">In der Nähe suchen</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Zeigt Seiten und Logbuch-Einträge mit Standort im gewählten Umkreis.</p>
            </div>
            <button type="button" @click="closeNearbyDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </div>

        <div class="mt-4 flex items-center justify-between gap-2">
            <button type="button" @click="useNearbyCurrentLocation" :disabled="nearbyLocating" class="btn btn-quiet">
                <span x-icon="map-pin"></span><span x-text="nearbyLocating ? 'Einen Moment…' : 'Aktueller Standort'"></span>
            </button>
            <p class="text-xs" style="color: var(--color-text-muted);">Oder auf die Karte tippen</p>
        </div>

        <div x-ref="nearbyMap" class="mt-3 h-56 w-full overflow-hidden rounded-lg border" style="border-color: var(--color-border);"></div>

        <label for="nearby-radius" class="mt-4 flex items-center justify-between text-sm font-medium">
            <span>Umkreis</span>
            <span x-text="nearbyRadiusLabel()"></span>
        </label>
        <input id="nearby-radius" x-model.number="nearbyRadiusKm" @input="updateNearbyRadius" @change="saveNearbyRadiusPreference" type="range" min="0.1" max="50" step="0.1" class="mt-2 w-full">

        <p x-show="nearbyError" x-cloak x-text="nearbyError" class="mt-4 text-sm" style="color: var(--color-danger);" role="alert"></p>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="closeNearbyDialog" class="btn btn-quiet">Abbrechen</button>
            <button type="button" @click="runNearbySearch" :disabled="nearbyLoading || !nearbyCenter" class="btn btn-primary" x-text="nearbyLoading ? 'Suche…' : 'Suchen'"></button>
        </div>
    </div>
</div>
