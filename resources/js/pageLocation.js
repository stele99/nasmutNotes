import { apiFetch } from './api.js';
import { isLocationSupported, locationLabel, locationMapUrl, parseLocationInput, requestLocation } from './geo.js';

/**
 * Aufnahmeort einer Seite (FR-NOTE-25). Wird in die Alpine-Komponenten aller
 * Seitentypen gemischt - Notiz, Aufgabenliste und Logbuch führen ihn gleich.
 *
 * Die aufnehmende Komponente bringt `pageId` und `canEditPage` mit und ruft in
 * ihrem `init()` einmal `initPageLocation(root)` auf.
 */
export function pageLocationMixin() {
  return {
    pageLocation: null,
    pageIsShared: Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    locationSupported: isLocationSupported(),
    locationDialogOpen: false,
    locationInput: '',
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
     * aktuellen Standort und - um ihn auf einen anderen zu verschieben - die
     * Eingabe von Koordinaten oder eines kopierten Kartenlinks.
     */
    openLocationDialog() {
      if (!this.canEditLocation()) {
        return;
      }
      this.locationError = '';
      this.locationInput = this.pageLocation
        ? `${this.pageLocation.lat}, ${this.pageLocation.lon}`
        : '';
      this.locationDialogOpen = true;
    },

    closeLocationDialog() {
      this.locationDialogOpen = false;
      this.locationBusy = false;
      this.locationError = '';
    },

    async useCurrentLocation() {
      if (!this.locationSupported) {
        this.locationError = 'Dieser Browser bietet keine Ortung an.';
        return;
      }

      this.locationBusy = true;
      this.locationError = '';
      try {
        const location = await requestLocation();
        if (!location) {
          this.locationError = 'Der Standort konnte nicht ermittelt werden. '
            + 'Vielleicht ist die Ortung abgelehnt oder gerade kein Signal da.';
          return;
        }
        await this.saveLocation(location);
      } finally {
        this.locationBusy = false;
      }
    },

    async applyLocationInput() {
      const location = parseLocationInput(this.locationInput);
      if (!location) {
        this.locationError = 'Bitte Koordinaten wie „48.7758, 9.1829" oder einen Kartenlink einfügen.';
        return;
      }

      this.locationBusy = true;
      try {
        await this.saveLocation(location);
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
  };
}
