import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export const DEFAULT_MAP_CENTER = [51.1657, 10.4515];
export const DEFAULT_MAP_ZOOM = 5;
export const LOCATED_MAP_ZOOM = 15;

function markerIcon() {
  return L.divIcon({
    className: 'location-marker',
    html: '<span></span>',
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  });
}

/** Gemeinsame Leaflet-Auswahl für Seitenstandort und Umkreissuche. */
export function createLocationMap({ element, center, zoom, radiusMeters = null, onChange }) {
  const map = L.map(element, { attributionControl: false }).setView(center, zoom);
  L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap-Mitwirkende').addTo(map);
  L.tileLayer('/api/map-tiles/{z}/{x}/{y}', { maxZoom: 19 }).addTo(map);

  const marker = L.marker(center, { draggable: true, icon: markerIcon() }).addTo(map);
  const circle = radiusMeters === null ? null : L.circle(center, {
    radius: radiusMeters,
    color: '#2563eb',
    weight: 1,
    fillOpacity: 0.08,
  }).addTo(map);

  marker.on('dragend', () => {
    const point = marker.getLatLng();
    onChange(point.lat, point.lng);
  });
  map.on('click', (event) => onChange(event.latlng.lat, event.latlng.lng));
  const resizeTimer = window.setTimeout(() => map.invalidateSize(), 0);

  return {
    setCenter(lat, lon, panTo = true) {
      marker.setLatLng([lat, lon]);
      circle?.setLatLng([lat, lon]);
      if (panTo) {
        map.setView([lat, lon], Math.max(map.getZoom(), LOCATED_MAP_ZOOM));
      }
    },
    setRadius(radius) {
      circle?.setRadius(radius);
    },
    destroy() {
      window.clearTimeout(resizeTimer);
      map.remove();
    },
  };
}
