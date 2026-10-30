import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';

/**
 * Codeblock mit Kopierknopf oben rechts.
 *
 * Der Knopf sitzt nicht im <pre>, sondern in einer umhüllenden <div>: Das
 * <pre> scrollt waagerecht, ein Knopf darin wanderte mit dem Inhalt aus dem
 * Bild. Gespeichert wird weiterhin nur der Knoten `codeBlock` - die Hülle
 * existiert allein im Editor, getHTML()/Server-Rendering bleiben unverändert.
 */

const COPY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
  + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
  + ' aria-hidden="true">'
  + '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>'
  + '<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';

const CHECK_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
  + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
  + ' aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

async function copyText(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  // Ohne Clipboard-API (z. B. ohne HTTPS): klassischer Umweg über ein
  // unsichtbares Textfeld.
  const input = document.createElement('textarea');
  input.value = text;
  input.style.position = 'fixed';
  input.style.opacity = '0';
  document.body.appendChild(input);
  input.select();
  const copied = document.execCommand('copy');
  input.remove();
  if (!copied) {
    throw new Error('Zwischenablage nicht verfügbar.');
  }
}

export const CopyableCodeBlock = CodeBlockLowlight.extend({
  addNodeView() {
    const prefix = this.options.languageClassPrefix;

    return ({ node }) => {
      let current = node;

      const dom = document.createElement('div');
      dom.className = 'note-code-block';
      const pre = document.createElement('pre');
      const code = document.createElement('code');
      const applyLanguage = (language) => {
        code.className = language ? `${prefix}${language}` : '';
      };
      applyLanguage(node.attrs.language);
      pre.appendChild(code);
      dom.appendChild(pre);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'note-code-copy';
      button.title = 'Code kopieren';
      button.setAttribute('aria-label', 'Code kopieren');
      button.contentEditable = 'false';
      button.innerHTML = COPY_ICON;
      dom.appendChild(button);

      let resetTimer = null;
      const showResult = (label, icon) => {
        button.innerHTML = icon;
        button.title = label;
        button.setAttribute('aria-label', label);
        button.classList.toggle('is-copied', icon === CHECK_ICON);
        clearTimeout(resetTimer);
        resetTimer = setTimeout(() => {
          button.innerHTML = COPY_ICON;
          button.title = 'Code kopieren';
          button.setAttribute('aria-label', 'Code kopieren');
          button.classList.remove('is-copied');
        }, 1500);
      };

      // Wie beim Bild-Knopf: ProseMirror darf den Klick weder als Auswahl noch
      // als Fokuswechsel in den Codeblock werten.
      button.addEventListener('mousedown', (event) => {
        event.preventDefault();
        event.stopPropagation();
      });
      button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        try {
          await copyText(current.textContent);
          showResult('Kopiert', CHECK_ICON);
        } catch (error) {
          showResult('Kopieren fehlgeschlagen', COPY_ICON);
        }
      });

      return {
        dom,
        contentDOM: code,
        update(updated) {
          if (updated.type !== current.type) {
            return false;
          }
          if (updated.attrs.language !== current.attrs.language) {
            applyLanguage(updated.attrs.language);
          }
          current = updated;

          return true;
        },
        // Ereignisse und DOM-Änderungen am Knopf (Symbolwechsel nach dem
        // Kopieren) gehören nicht zum Dokument.
        stopEvent(event) {
          return event.target instanceof Node && button.contains(event.target);
        },
        ignoreMutation(mutation) {
          return mutation.type !== 'selection' && button.contains(mutation.target);
        },
        destroy() {
          clearTimeout(resetTimer);
        },
      };
    };
  },
});
