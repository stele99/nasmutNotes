import { apiFetch } from './api.js';
import { isLocationSupported, locationLabel, locationMapUrl, parseLocationInput, requestLocation } from './geo.js';
import { createLocationMap, DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM, LOCATED_MAP_ZOOM } from './locationMap.js';

/**
 * Aufnahmeort einer Seite (FR-NOTE-25). Wird in die Alpine-Komponenten aller
 * Seitentypen gemischt - Notiz, Aufgabenliste und Logbuch führen ihn gleich.
 *
 * Die aufnehmende Komponente bringt `pageId` und `canEditPage` mit und ruft in
 * ihrem `init()` einmal `initPageLocation(root)` auf.
 */
export function pageLocationMixin() {
  let locationMap = null;
  let searchSequence = 0;

  return {
    pageLocation: null,
    pageIsShared: Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    locationSupported: isLocationSupported(),
    locationDialogOpen: false,
    locationInput: '',
    locationDraft: null,
    locationSearchQuery: '',
    locationSearchResults: [],
    locationSearching: false,
    locationLocating: false,
    locationBusy: false,
    locationError: '',

    /**
     * Aufnahmeort aus dem servergerenderten Markup. Fehlt eine der Koordinaten,
     * gilt die Seite als ohne Ort angelegt.
     */
    initPageLocation(root) {
      this.pageIsShared = root?.dataset.pageIsShared === '1'
        || Boolean(window.__CURRENT_PAGE_IS_SHARED__);

      const lat = Number.parseFloat(root?.dataset.pageLat ?? '');
      const lon = Number.parseFloat(root?.dataset.pageLon ?? '');
      if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        this.pageLocation = null;
        return;
      }

      const accuracy = Number.parseFloat(root?.dataset.pageAccuracy ?? '');
      this.pageLocation = {
        lat,
        lon,
        accuracy: Number.isFinite(accuracy) ? accuracy : null,
        label: root?.dataset.pageAddress || null,
      };
    },

    locationLabel() {
      return locationLabel(this.pageLocation);
    },

    locationMapUrl() {
      return locationMapUrl(this.pageLocation);
    },

    /** Der Ort gehört zur Seite, nicht zum Inhalt - Freigaben ändern ihn nicht. */
    canEditLocation() {
      return this.canEditPage && !this.pageIsShared;
    },

    /**
     * Der Ort wird auf Klick gesetzt (FR-NOTE-25). Der Dialog bietet den
     * aktuellen Standort, eine Kartenwahl, die Adresssuche und Koordinaten.
     */
    openLocationDialog() {
      if (!this.canEditLocation()) {
        return;
      }
      this.locationError = '';
      this.locationSearchQuery = '';
      this.locationSearchResults = [];
      this.locationDraft = this.pageLocation ? { ...this.pageLocation } : null;
      this.locationInput = this.pageLocation
        ? `${this.pageLocation.lat}, ${this.pageLocation.lon}`
        : '';
      this.locationDialogOpen = true;
      this.$nextTick(() => this.initLocationMap());
    },

    closeLocationDialog() {
      ++searchSequence;
      locationMap?.destroy();
      locationMap = null;
      this.locationDialogOpen = false;
      this.locationBusy = false;
      this.locationSearching = false;
      this.locationLocating = false;
      this.locationError = '';
    },

    initLocationMap() {
      const element = this.$refs.locationMap;
      if (!element || locationMap) {
        return;
      }
      const center = this.locationDraft
        ? [this.locationDraft.lat, this.locationDraft.lon]
        : DEFAULT_MAP_CENTER;
      locationMap = createLocationMap({
        element,
        center,
        zoom: this.locationDraft ? LOCATED_MAP_ZOOM : DEFAULT_MAP_ZOOM,
        onChange: (lat, lon) => this.setLocationDraft({ lat, lon, accuracy: null }, false),
      });
    },

    setLocationDraft(location, panTo = true) {
      this.locationDraft = location;
      this.locationInput = `${location.lat.toFixed(6)}, ${location.lon.toFixed(6)}`;
      locationMap?.setCenter(location.lat, location.lon, panTo);
      this.locationError = '';
    },

    async useCurrentLocation() {
      if (!this.locationSupported) {
        this.locationError = 'Dieser Browser bietet keine Ortung an.';
        return;
      }

      const sequence = searchSequence;
      this.locationLocating = true;
      this.locationError = '';
      try {
        const location = await requestLocation();
        if (sequence !== searchSequence) {
          return;
        }
        if (!location) {
          this.locationError = 'Der Standort konnte nicht ermittelt werden. '
            + 'Vielleicht ist die Ortung abgelehnt oder gerade kein Signal da.';
          return;
        }
        this.setLocationDraft(location);
      } finally {
        this.locationLocating = false;
      }
    },

    async searchLocationAddress() {
      const query = this.locationSearchQuery.trim();
      if (query.length < 2) {
        this.locationError = 'Bitte mindestens zwei Zeichen für die Ortssuche eingeben.';
        return;
      }

      const sequence = ++searchSequence;
      this.locationSearching = true;
      this.locationSearchResults = [];
      this.locationError = '';
      try {
        const data = await apiFetch(`/api/geocode/search?q=${encodeURIComponent(query)}`);
        if (sequence !== searchSequence) {
          return;
        }
        this.locationSearchResults = data.results || [];
        if (this.locationSearchResults.length === 0) {
          this.locationError = 'Kein passender Ort gefunden.';
        }
      } catch (error) {
        if (sequence === searchSequence) {
          this.locationError = error.message || 'Die Ortssuche ist fehlgeschlagen.';
        }
      } finally {
        if (sequence === searchSequence) {
          this.locationSearching = false;
        }
      }
    },

    selectLocationSearchResult(result) {
      this.locationSearchQuery = result.label;
      this.locationSearchResults = [];
      this.setLocationDraft({ lat: Number(result.lat), lon: Number(result.lon), accuracy: null });
    },

    locationSearchResultKey(result) {
      return `${result.lat},${result.lon}`;
    },

    async applyLocationInput() {
      const location = parseLocationInput(this.locationInput);
      if (!location) {
        this.locationError = 'Bitte Koordinaten wie „48.7758, 9.1829" oder einen Kartenlink einfügen.';
        return;
      }

      this.locationBusy = true;
      try {
        const sameAsDraft = this.locationDraft
          && this.locationDraft.lat === location.lat
          && this.locationDraft.lon === location.lon;
        await this.saveLocation({
          ...location,
          accuracy: sameAsDraft ? this.locationDraft.accuracy : null,
        });
      } finally {
        this.locationBusy = false;
      }
    },

    async removeLocation() {
      this.locationBusy = true;
      try {
        await this.saveLocation(null);
      } finally {
        this.locationBusy = false;
      }
    },

    async saveLocation(location) {
      if (!navigator.onLine) {
        this.locationError = 'Der Standort kann nur mit Internetverbindung geändert werden.';
        return;
      }

      this.locationError = '';
      try {
        const page = await apiFetch(`/api/pages/${this.pageId}`, {
          method: 'PATCH',
          body: JSON.stringify({ location }),
        });
        this.pageLocation = page.location || null;
        this.closeLocationDialog();
      } catch (error) {
        this.locationError = error.message || 'Der Standort konnte nicht gespeichert werden.';
      }
    },

    destroyPageLocation() {
      ++searchSequence;
      locationMap?.destroy();
      locationMap = null;
    },
  };
}
