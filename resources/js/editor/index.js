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
}) {
  return new Editor({
    element,
    editable,
    content,
    extensions: [
      StarterKit.configure({ codeBlock: false, link: false }),
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
      TaskItem.configure({ nested: true }),
    ],
    editorProps: {
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
