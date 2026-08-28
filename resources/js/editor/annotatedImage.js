import { Image } from '@tiptap/extension-image';
import { normalizeAnnotations, serializeAnnotations } from './annotations/schema.js';
import { renderOverlay } from './annotations/render.js';

/**
 * Bildknoten mit Annotations-Overlay (FR-ANNO-01/07).
 *
 * Zwei Dinge sind hier heikel und deshalb ausführlich kommentiert:
 *
 * 1. Das Attribut muss den Weg über ein DOM-Attribut nehmen. ProseMirror
 *    serialisiert beim Kopieren innerhalb des Editors über HTML; ohne
 *    `data-annotations` verlöre eine kopierte Bildzeile ihre Annotationen.
 * 2. `Image` ist mit `resize.enabled` konfiguriert und bringt bereits eine
 *    NodeView mit (data-resize-container/-wrapper/-handle). Statt sie zu
 *    ersetzen, wird sie umhüllt.
 */

function attachLayer(dom) {
  const image = dom instanceof HTMLImageElement ? dom : dom.querySelector('img');
  if (image === null) {
    return null;
  }

  const host = image.parentElement ?? dom;
  host.classList.add('note-image-host');
  const layer = document.createElement('span');
  layer.className = 'note-image-overlay';
  layer.setAttribute('aria-hidden', 'true');
  host.appendChild(layer);

  // Das Overlay wird auf den Kasten des <img> gelegt, nicht auf den des
  // Wirtsknotens: Der Wirt ist beim zentrierten Bild breiter als das Bild
  // selbst, und das Overlay säße dann daneben.
  const place = () => {
    layer.style.left = `${image.offsetLeft}px`;
    layer.style.top = `${image.offsetTop}px`;
    layer.style.width = `${image.offsetWidth}px`;
    layer.style.height = `${image.offsetHeight}px`;
  };

  place();
  const observer = new ResizeObserver(place);
  observer.observe(image);
  image.addEventListener('load', place);

  return {
    layer,
    destroy() {
      observer.disconnect();
      image.removeEventListener('load', place);
      layer.remove();
    },
  };
}

export const AnnotatedImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      annotations: {
        default: null,
        parseHTML: (element) => {
          const raw = element.getAttribute('data-annotations');
          if (raw === null || raw === '') {
            return null;
          }
          try {
            return normalizeAnnotations(JSON.parse(raw));
          } catch (error) {
            // Fremdes oder abgeschnittenes JSON: Das Bild bleibt, die
            // Annotation entfällt.
            return null;
          }
        },
        renderHTML: (attributes) => {
          const value = serializeAnnotations(attributes.annotations);

          return value === null ? {} : { 'data-annotations': JSON.stringify(value) };
        },
      },
    };
  },

  addNodeView() {
    const parentFactory = this.parent?.();
    if (typeof parentFactory !== 'function') {
      // Ohne NodeView der Basis-Erweiterung gäbe es auch keine Skaliergriffe;
      // dann trägt der Editor nur das nackte Bild und das Overlay entfällt.
      return null;
    }

    return (props) => {
      const view = parentFactory(props);
      const attached = attachLayer(view.dom);
      if (attached === null) {
        return view;
      }

      let current = props.node.attrs.annotations;
      renderOverlay(attached.layer, current);

      // Die NodeView der Basis-Erweiterung ist eine Klasseninstanz. Sie wird
      // ergänzt statt kopiert - ein Spread ({ ...view }) verlöre alle
      // Methoden, die am Prototyp hängen.
      const parentUpdate = typeof view.update === 'function' ? view.update.bind(view) : null;
      const parentDestroy = typeof view.destroy === 'function' ? view.destroy.bind(view) : null;

      view.update = (node, decorations, innerDecorations) => {
        const accepted = parentUpdate
          ? parentUpdate(node, decorations, innerDecorations)
          : node.type.name === 'image';
        if (!accepted) {
          return false;
        }
        if (node.attrs.annotations !== current) {
          current = node.attrs.annotations;
          renderOverlay(attached.layer, current);
        }

        return true;
      };

      view.destroy = () => {
        attached.destroy();
        parentDestroy?.();
      };

      return view;
    };
  },
});
