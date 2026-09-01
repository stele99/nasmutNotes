import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { prepareImageForUpload } from './imagePrepare.js';

const imageUploadPluginKey = new PluginKey('protectedImageUpload');

function imageFiles(fileList) {
  return Array.from(fileList || []).filter((file) => file.type.startsWith('image/'));
}

function clipboardImageFiles(clipboardData) {
  if (!clipboardData) {
    return [];
  }

  const files = Array.from(clipboardData.items || [])
    .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
    .map((item) => item.getAsFile())
    .filter((file) => file instanceof File);

  return files.length > 0 ? files : imageFiles(clipboardData.files);
}

export const ProtectedImageUpload = Extension.create({
  name: 'protectedImageUpload',

  addOptions() {
    return {
      onUpload: null,
      onError: null,
      onPendingChange: null,
    };
  },

  addStorage() {
    return { uploadFiles: null };
  },

  addCommands() {
    return {
      /**
       * Einstieg für Datei- und Kamera-Auswahl aus der Werkzeugleiste. Läuft
       * über denselben Weg wie Drag & Drop und Einfügen: Platzhalter setzen,
       * hochladen, Bildknoten einsetzen.
       */
      insertImageFiles: (fileList) => ({ view, state }) => {
        const files = imageFiles(fileList);
        const upload = this.editor.storage.protectedImageUpload?.uploadFiles;
        if (files.length === 0 || typeof upload !== 'function') {
          return false;
        }

        // Der Upload dispatcht eigene Transaktionen. Geschähe das innerhalb
        // dieser Chain, wäre die beim Aufruf erzeugte Chain-Transaktion gegen
        // den inzwischen neuen State "mismatched" - deshalb erst nach dem
        // Abschluss der Chain anstoßen.
        Promise.resolve().then(() => {
          if (!this.editor.isDestroyed) {
            upload(files, view, state.selection.from);
          }
        });

        return true;
      },
    };
  },

  addProseMirrorPlugins() {
    const editor = this.editor;
    const options = this.options;
    let pendingUploads = 0;

    const setPendingUploads = (value) => {
      pendingUploads = value;
      options.onPendingChange?.(pendingUploads);
    };

    const upload = (files, view, position) => {
      if (files.length === 0 || typeof options.onUpload !== 'function') {
        return;
      }

      const id = {};
      view.dispatch(view.state.tr.setMeta(imageUploadPluginKey, {
        add: { id, position },
      }));
      setPendingUploads(pendingUploads + files.length);

      Promise.all(files.map((file) => prepareImageForUpload(file).then((prepared) => options.onUpload(prepared))))
        .then((attachments) => {
          if (editor.isDestroyed) {
            return;
          }

          const decorations = imageUploadPluginKey.getState(view.state);
          const placeholder = decorations?.find(null, null, (spec) => spec.id === id)[0];
          if (!placeholder) {
            return;
          }

          view.dispatch(view.state.tr.setMeta(imageUploadPluginKey, { remove: { id } }));
          editor.commands.insertContentAt(
            placeholder.from,
            attachments.map((attachment) => ({
              type: 'image',
              attrs: {
                src: attachment.src,
                alt: 'Eingefügter Screenshot',
                title: null,
                width: attachment.width,
                height: attachment.height,
              },
            })),
          );
        })
        .catch((error) => {
          if (!editor.isDestroyed) {
            view.dispatch(view.state.tr.setMeta(imageUploadPluginKey, { remove: { id } }));
          }
          options.onError?.(error);
        })
        .finally(() => setPendingUploads(Math.max(0, pendingUploads - files.length)));
    };

    this.storage.uploadFiles = upload;

    return [
      new Plugin({
        key: imageUploadPluginKey,
        state: {
          init: () => DecorationSet.empty,
          apply(transaction, decorations) {
            let next = decorations.map(transaction.mapping, transaction.doc);
            const meta = transaction.getMeta(imageUploadPluginKey);
            if (meta?.add) {
              const placeholder = Decoration.widget(
                meta.add.position,
                () => {
                  const element = document.createElement('span');
                  element.className = 'image-upload-placeholder';
                  element.textContent = 'Bild wird hochgeladen…';
                  return element;
                },
                { id: meta.add.id },
              );
              next = next.add(transaction.doc, [placeholder]);
            }
            if (meta?.remove) {
              next = next.remove(next.find(null, null, (spec) => spec.id === meta.remove.id));
            }
            return next;
          },
        },
        props: {
          decorations(state) {
            return imageUploadPluginKey.getState(state);
          },
          handlePaste(view, event) {
            if (!editor.isEditable) {
              return false;
            }
            const files = clipboardImageFiles(event.clipboardData);
            if (files.length === 0) {
              return false;
            }
            event.preventDefault();
            upload(files, view, view.state.selection.from);
            return true;
          },
          handleDrop(view, event) {
            if (!editor.isEditable) {
              return false;
            }
            const files = imageFiles(event.dataTransfer?.files);
            if (files.length === 0) {
              return false;
            }
            event.preventDefault();
            const position = view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos
              ?? view.state.selection.from;
            upload(files, view, position);
            return true;
          },
        },
      }),
    ];
  },
});
