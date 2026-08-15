import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Link } from '@tiptap/extension-link';
import { Image } from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { TaskList } from '@tiptap/extension-task-list';
import { TaskItem } from '@tiptap/extension-task-item';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { createLowlight, common } from 'lowlight';
import { ProtectedImageUpload } from './imageUpload.js';
import { HEADING_LEVELS, sanitizePastedHtml } from './sanitize.js';

const lowlight = createLowlight(common);

export function createEditor({
  element,
  content,
  editable = true,
  onUpdate,
  onTransaction,
  onImageUpload,
  onImageUploadError,
  onPendingImageUploads,
  onLinkClick,
  scrollInset,
}) {
  return new Editor({
    element,
    editable,
    content,
    extensions: [
      // Nur H1-H3: der Server lehnt tiefere Ebenen ab, StarterKit würde von sich
      // aus H1-H6 anlegen.
      StarterKit.configure({ codeBlock: false, link: false, heading: { levels: HEADING_LEVELS } }),
      CodeBlockLowlight.configure({ lowlight }),
      Link.configure({
        openOnClick: false,
        enableClickSelection: true,
        autolink: true,
        HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
      }),
      Image.configure({
        allowBase64: false,
        inline: false,
        resize: {
          enabled: true,
          directions: ['bottom-left', 'bottom-right'],
          minWidth: 120,
          minHeight: 80,
          alwaysPreserveAspectRatio: true,
        },
        HTMLAttributes: {
          loading: 'lazy',
          decoding: 'async',
        },
      }),
      ProtectedImageUpload.configure({
        onUpload: onImageUpload,
        onError: onImageUploadError,
        onPendingChange: onPendingImageUploads,
      }),
      TableKit.configure({ resizable: false }),
      TaskList,
      // Die NodeView von TaskItem setzt nur `data-checked` auf das <li>, nicht
      // `data-type` wie beim reinen HTML-Rendering. Ohne dieses Attribut griffe
      // das Checklisten-Layout im Editor nicht.
      TaskItem.configure({ nested: true, HTMLAttributes: { 'data-type': 'taskItem' } }),
    ],
    editorProps: {
      // Notizkopf und Werkzeugleiste kleben über dem Editor. Ohne diese Angaben
      // holt ProseMirror die Einfügemarke nur bis an die Oberkante des
      // Scroll-Containers - also unter die Leisten. Schwelle und Abstand sind
      // bewusst dasselbe Objekt: Sobald die Marke in den Bereich der Leisten
      // gerät, wird sie genau darunter gescrollt. ProseMirror liest die Werte
      // bei jedem Scrollvorgang neu, notePage.js hält sie an der gemessenen
      // Höhe der Leisten.
      scrollThreshold: scrollInset,
      scrollMargin: scrollInset,
      transformPastedHTML: sanitizePastedHtml,
      handleClick(_view, position, event) {
        const target = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(target instanceof HTMLAnchorElement) || typeof onLinkClick !== 'function') {
          return false;
        }

        event.preventDefault();
        const rect = target.getBoundingClientRect();
        onLinkClick({
          href: target.href,
          position,
          left: rect.left,
          top: rect.bottom + 8,
        });
        return true;
      },
    },
    onUpdate: ({ editor }) => {
      if (onUpdate) {
        onUpdate(editor.getJSON());
      }
    },
    onTransaction: ({ editor }) => {
      if (onTransaction) {
        onTransaction(editor);
      }
    },
  });
}
