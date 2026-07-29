import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { apiFetch } from './api.js';
import { requestLocation } from './geo.js';

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
const DEFAULT_CENTER = [51.1657, 10.4515]; // Geografische Mitte Deutschlands - nur ohne bekannten Standort sichtbar.
const DEFAULT_ZOOM = 5;
const LOCATED_ZOOM = 15;

/**
 * Leaflets Standardmarker leitet den Pfad seiner Bilddateien zur Laufzeit aus
 * einer CSS-Hintergrundfarbe her (`_detectIconPath`). Unter Vite wird dieses
 * Bild aber als `data:`-URI eingebettet, sodass die Herleitung ins Leere
 * liefe und das Symbol in der Produktion fehlen würde. Ein einfacher,
 * eingefärbter Punkt passt außerdem besser zum Rest der Anwendung und braucht
 * keinerlei Bild-Asset.
 */
function centerMarkerIcon() {
  return L.divIcon({
    className: 'nearby-marker',
    html: '<span></span>',
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  });
}

export function nearbySearchMixin() {
  // Leaflet hält eigenen veränderlichen Zustand (DOM, Ereignis-Handler) und
  // darf wie der ProseMirror-Editor nicht durch Alpine reaktiv gemacht werden.
  let map = null;
  let marker = null;
  let circle = null;

  return {
    nearbyDialogOpen: false,
    nearbyRadiusKm: DEFAULT_RADIUS_KM,
    nearbyCenter: null, // { lat, lon }
    nearbyLocating: false,
    nearbyLoading: false,
    nearbyError: '',
    nearbyActive: false,
    nearbyResults: [],

    openNearbyDialog() {
      this.nearbyError = '';
      this.nearbyDialogOpen = true;
      this.$nextTick(() => {
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
      if (!element || map) {
        return;
      }

      const startView = this.nearbyCenter
        ? [this.nearbyCenter.lat, this.nearbyCenter.lon]
        : DEFAULT_CENTER;
      map = L.map(element, { attributionControl: false }).setView(startView, this.nearbyCenter ? LOCATED_ZOOM : DEFAULT_ZOOM);
      L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap-Mitwirkende').addTo(map);
      L.tileLayer('/api/map-tiles/{z}/{x}/{y}', { maxZoom: 19 }).addTo(map);

      marker = L.marker(startView, { draggable: true, icon: centerMarkerIcon() }).addTo(map);
      circle = L.circle(startView, {
        radius: this.nearbyRadiusKm * 1000,
        color: '#2563eb',
        weight: 1,
        fillOpacity: 0.08,
      }).addTo(map);

      marker.on('dragend', () => {
        const { lat, lng } = marker.getLatLng();
        this.setNearbyCenter(lat, lng, false);
      });
      map.on('click', (event) => {
        this.setNearbyCenter(event.latlng.lat, event.latlng.lng, false);
      });

      // Das Dialogfenster kann beim ersten Layout noch die falsche Größe
      // gemeldet haben; ohne diesen Nachtrag bliebe die Karte teils grau.
      window.setTimeout(() => map?.invalidateSize(), 0);
    },

    destroyNearbyMap() {
      map?.remove();
      map = null;
      marker = null;
      circle = null;
    },

    setNearbyCenter(lat, lon, panTo = true) {
      this.nearbyCenter = { lat, lon };
      marker?.setLatLng([lat, lon]);
      circle?.setLatLng([lat, lon]);
      if (panTo) {
        map?.setView([lat, lon], Math.max(map.getZoom(), LOCATED_ZOOM));
      }
    },

    updateNearbyRadius() {
      circle?.setRadius(this.nearbyRadiusKm * 1000);
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
        this.nearbyActive = true;
        this.searchQuery = '';
        this.searchResults = [];
        this.closeNearbyDialog();
      } catch (error) {
        this.nearbyError = error.message || 'Die Umkreissuche ist fehlgeschlagen.';
      } finally {
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
