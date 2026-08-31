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

/**
 * Stift-Symbol des Bild-Knopfs - bewusst direkt eingebettet statt über
 * icons.js: Der Editor-Bundle soll dafür nicht die komplette Icon-Tabelle
 * mitziehen.
 */
const PENCIL_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
  + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
  + ' aria-hidden="true">'
  + '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>'
  + '<path d="m15 5 4 4"/></svg>';

/**
 * `onAnnotate` ist null, wenn der Editor nur lesend ist - dann entsteht kein
 * Knopf. Der Knopf wird nicht per Alpine gebunden, sondern schickt ein
 * aufsteigendes Ereignis mit der Knotenposition: Die NodeView kennt den
 * Alpine-Zustand nicht (und umgekehrt).
 */
function attachLayer(dom, onAnnotate) {
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

  let tools = null;
  if (typeof onAnnotate === 'function') {
    tools = document.createElement('span');
    tools.className = 'note-image-tools';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'note-image-annotate';
    button.title = 'Bild beschriften';
    button.setAttribute('aria-label', 'Bild beschriften');
    button.innerHTML = PENCIL_ICON;
    // ProseMirror darf den Klick weder als Auswahl noch als Drag-Start
    // werten; der Vollbild-Betrachter (Capture-Phase am Editor) greift am
    // Knopf ohnehin nicht, weil er nur auf <img> reagiert.
    button.addEventListener('mousedown', (event) => {
      event.preventDefault();
      event.stopPropagation();
    });
    button.addEventListener('touchstart', (event) => {
      event.stopPropagation();
    });
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      onAnnotate();
    });
    tools.appendChild(button);
    host.appendChild(tools);
  }

  // Overlay und Knopfkasten werden auf den Kasten des <img> gelegt, nicht auf
  // den des Wirtsknotens: Der Wirt ist beim zentrierten Bild breiter als das
  // Bild selbst, und beide säßen dann daneben.
  const place = () => {
    for (const target of [layer, tools]) {
      if (target === null) {
        continue;
      }
      target.style.left = `${image.offsetLeft}px`;
      target.style.top = `${image.offsetTop}px`;
      target.style.width = `${image.offsetWidth}px`;
      target.style.height = `${image.offsetHeight}px`;
    }
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
      tools?.remove();
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
      // Der Knopf entsteht nur im bearbeitbaren Editor: In nur lesenden
      // Freigaben gibt es keinen Einstieg in den Annotationseditor.
      const onAnnotate = props.editor.isEditable
        ? () => {
            const pos = props.getPos();
            if (typeof pos !== 'number') {
              return;
            }
            view.dom.dispatchEvent(new CustomEvent('open-image-annotator', {
              bubbles: true,
              detail: { pos },
            }));
          }
        : null;
      const attached = attachLayer(view.dom, onAnnotate);
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
