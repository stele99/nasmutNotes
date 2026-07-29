import { apiFetch } from './api.js';
import { requestLocation } from './geo.js';
import {
  createLocationMap,
  DEFAULT_MAP_CENTER,
  DEFAULT_MAP_ZOOM,
  LOCATED_MAP_ZOOM,
} from './locationMap.js';

/**
 * Umkreissuche über Seiten und Logbuch-Einträge mit Aufnahmeort (FR-NOTE-27).
 * Mischt in `pageList` einen Standortwähler mit kleiner Karte: Mittelpunkt per
 * aktuellem Standort, Klick auf die Karte oder Ziehen der Markierung, dazu ein
 * Regler für den Umkreis (Vorgabe 1 km).
 *
 * Die Kartenkacheln kommen über `/api/map-tiles/{z}/{x}/{y}` - der Server holt
 * sie beim Kartendienst, nicht der Browser (siehe `MapTileProxy`). So bleibt
 * die IP-Adresse des Nutzers dem Kartendienst unbekannt und die
 * Content-Security-Policy unverändert (`img-src 'self'` reicht).
 */

const DEFAULT_RADIUS_KM = 1;
let radiusPreference = null;

function loadRadiusPreference() {
  if (!radiusPreference) {
    radiusPreference = apiFetch('/api/session')
      .then((data) => Number(data?.user?.nearby_search_radius_km || DEFAULT_RADIUS_KM))
      .catch(() => DEFAULT_RADIUS_KM);
  }

  return radiusPreference;
}

export function nearbySearchMixin() {
  // Leaflet hält eigenen veränderlichen Zustand (DOM, Ereignis-Handler) und
  // darf wie der ProseMirror-Editor nicht durch Alpine reaktiv gemacht werden.
  let locationMap = null;

  return {
    nearbyDialogOpen: false,
    nearbyRadiusKm: DEFAULT_RADIUS_KM,
    nearbyCenter: null, // { lat, lon }
    nearbyLocating: false,
    nearbyLoading: false,
    nearbyError: '',
    nearbyActive: false,
    nearbyResults: [],
    nearbyResultsRadiusKm: null,

    openNearbyDialog() {
      this.nearbyError = '';
      this.nearbyDialogOpen = true;
      this.$nextTick(async () => {
        this.nearbyRadiusKm = await loadRadiusPreference();
        if (!this.nearbyDialogOpen) {
          return;
        }
        this.initNearbyMap();
        if (!this.nearbyCenter) {
          void this.useNearbyCurrentLocation();
        }
      });
    },

    closeNearbyDialog() {
      this.nearbyDialogOpen = false;
      this.destroyNearbyMap();
    },

    initNearbyMap() {
      const element = this.$refs.nearbyMap;
      if (!element || locationMap) {
        return;
      }

      const startView = this.nearbyCenter
        ? [this.nearbyCenter.lat, this.nearbyCenter.lon]
        : DEFAULT_MAP_CENTER;
      locationMap = createLocationMap({
        element,
        center: startView,
        zoom: this.nearbyCenter ? LOCATED_MAP_ZOOM : DEFAULT_MAP_ZOOM,
        radiusMeters: this.nearbyRadiusKm * 1000,
        onChange: (lat, lon) => this.setNearbyCenter(lat, lon, false),
      });
    },

    destroyNearbyMap() {
      locationMap?.destroy();
      locationMap = null;
    },

    setNearbyCenter(lat, lon, panTo = true) {
      this.nearbyCenter = { lat, lon };
      locationMap?.setCenter(lat, lon, panTo);
    },

    updateNearbyRadius() {
      locationMap?.setRadius(this.nearbyRadiusKm * 1000);
    },

    async saveNearbyRadiusPreference() {
      const radiusKm = Number(this.nearbyRadiusKm);
      try {
        const data = await apiFetch('/api/profile', {
          method: 'PATCH',
          body: JSON.stringify({ nearby_search_radius_km: radiusKm }),
        });
        this.nearbyRadiusKm = Number(data.nearby_search_radius_km);
        radiusPreference = Promise.resolve(this.nearbyRadiusKm);
      } catch (error) {
        this.nearbyError = error.message || 'Der Suchradius konnte nicht gespeichert werden.';
      }
    },

    async useNearbyCurrentLocation() {
      this.nearbyLocating = true;
      this.nearbyError = '';
      try {
        const location = await requestLocation();
        if (!location) {
          this.nearbyError = 'Der Standort konnte nicht ermittelt werden. Bitte auf die Karte tippen.';
          return;
        }
        this.setNearbyCenter(location.lat, location.lon);
      } finally {
        this.nearbyLocating = false;
      }
    },

    nearbyRadiusLabel() {
      return this.nearbyRadiusKm < 1
        ? `${Math.round(this.nearbyRadiusKm * 1000)} m`
        : `${this.nearbyRadiusKm.toFixed(this.nearbyRadiusKm < 10 ? 1 : 0)} km`;
    },

    async runNearbySearch() {
      if (!this.nearbyCenter) {
        this.nearbyError = 'Bitte zuerst einen Standort wählen.';
        return;
      }

      this.nearbyLoading = true;
      this.nearbyError = '';
      try {
        const query = new URLSearchParams({
          lat: String(this.nearbyCenter.lat),
          lon: String(this.nearbyCenter.lon),
          radius_km: String(this.nearbyRadiusKm),
        });
        const data = await apiFetch(`/api/search/nearby?${query.toString()}`);
        this.nearbyResults = data.results || [];
        this.nearbyResultsRadiusKm = this.nearbyRadiusKm;
        this.nearbyActive = true;
        if ('workspaceTab' in this) {
          this.workspaceTab = 'location';
        }
        this.searchQuery = '';
        this.searchResults = [];
        this.closeNearbyDialog();
      } catch (error) {
        this.nearbyError = error.message || 'Die Umkreissuche ist fehlgeschlagen.';
      } finally {
        this.nearbyLoading = false;
      }
    },

    /** Startseiten-Reiter: aktueller Gerätestandort mit festem Umkreis. */
    async runNearbyFromCurrentLocation(radiusKm) {
      this.nearbyLocating = true;
      this.nearbyLoading = true;
      this.nearbyError = '';
      this.nearbyResults = [];
      try {
        const location = await requestLocation();
        if (!location) {
          this.nearbyError = 'Der aktuelle Standort konnte nicht ermittelt werden.';
          return;
        }
        this.setNearbyCenter(location.lat, location.lon);

        const query = new URLSearchParams({
          lat: String(location.lat),
          lon: String(location.lon),
          radius_km: String(radiusKm),
        });
        const data = await apiFetch(`/api/search/nearby?${query.toString()}`);
        this.nearbyResults = data.results || [];
        this.nearbyResultsRadiusKm = radiusKm;
        this.nearbyActive = true;
      } catch (error) {
        this.nearbyError = error.message || 'Die Umkreissuche ist fehlgeschlagen.';
      } finally {
        this.nearbyLocating = false;
        this.nearbyLoading = false;
      }
    },

    clearNearby() {
      this.nearbyActive = false;
      this.nearbyResults = [];
    },

    nearbyResultsLabel() {
      const total = this.nearbyResults.length;

      return total === 1 ? '1 Treffer' : `${total} Treffer`;
    },

    nearbyResultsRadiusLabel() {
      const radiusKm = Number(this.nearbyResultsRadiusKm || 10);
      return radiusKm < 1
        ? `${Math.round(radiusKm * 1000)} m`
        : `${radiusKm.toFixed(radiusKm < 10 ? 1 : 0)} km`;
    },

    nearbyResultKey(item) {
      return item.page_id;
    },

    nearbyDistanceLabel(item) {
      const km = Number(item.distance_km || 0);

      return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
    },

    nearbyResultSubtitle(item) {
      const parts = [this.nearbyDistanceLabel(item)];
      if (item.label) {
        parts.push(item.label);
      }

      return parts.join(' · ');
    },

    async openNearbyResult(item) {
      const existing = this.pages.find((page) => Number(page.id) === Number(item.page_id));
      const page = existing || {
        id: item.page_id,
        type: item.page_type || 'log',
        title: item.title,
        is_shared: false,
        can_edit: true,
      };
      await this.navigate(page);
    },
  };
}
