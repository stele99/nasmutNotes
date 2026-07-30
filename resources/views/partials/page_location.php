<?php /* Aufnahmeort einer Seite (FR-NOTE-25). Der Link führt zu OpenStreetMap;
         die App selbst bindet keine Karte ein. Gesetzt wird der Ort auf Klick,
         sofern in den Einstellungen nicht die automatische Erfassung gewählt
         ist. Erwartet den pageLocationMixin in der umgebenden Komponente. */ ?>
<?php /* Eine lange Anschrift kürzt auf „…", statt die Zeile umbrechen zu lassen -
         daneben stehen je nach Seite noch Schaltflächen. */ ?>
<div class="mt-2 flex min-w-0 flex-nowrap items-center gap-2 text-xs" style="color: var(--color-text-muted);">
    <a x-show="pageLocation" x-cloak :href="locationMapUrl()" target="_blank" rel="noopener" class="inline-flex min-w-0 items-center gap-1.5 hover:underline" title="Aufnahmeort auf der Karte öffnen">
        <span class="shrink-0" x-icon="map-pin"></span><span class="truncate" x-text="locationLabel()"></span>
    </a>
    <button x-show="pageLocation && canEditLocation()" x-cloak type="button" @click="openLocationDialog" class="shrink-0 hover:underline" title="Standort ändern oder entfernen">Ändern</button>
    <button x-show="!pageLocation && canEditLocation()" x-cloak type="button" @click="openLocationDialog" class="inline-flex shrink-0 items-center gap-1.5 hover:underline" title="Standort zu dieser Seite hinzufügen">
        <span x-icon="map-pin"></span>Standort hinzufügen
    </button>
</div>
