import '../css/app.css';
import { decryptEnvelope, validateEnvelope } from './noteCrypto.js';
import { createEditor } from './editor/index.js';
import { sanitizeNoteDoc } from './editor/sanitize.js';

const form = document.querySelector('[data-copy-form]');
if (form instanceof HTMLFormElement) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const error = form.querySelector('[data-copy-error]');
    if (button instanceof HTMLButtonElement) button.disabled = true;
    if (error instanceof HTMLElement) error.textContent = '';
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({ notebook_id: new FormData(form).get('notebook_id') || null }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data?.error?.message || 'Die Kopie konnte nicht erstellt werden.');
      window.location.href = data.url;
    } catch (reason) {
      if (error instanceof HTMLElement) error.textContent = reason.message || 'Die Kopie konnte nicht erstellt werden.';
      if (button instanceof HTMLButtonElement) button.disabled = false;
    }
  });
}

const unlockForm = document.querySelector('[data-unlock-form]');
if (unlockForm instanceof HTMLFormElement) {
  unlockForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = unlockForm.querySelector('button[type="submit"]');
    const error = unlockForm.querySelector('[data-unlock-error]');
    if (button instanceof HTMLButtonElement) button.disabled = true;
    if (error instanceof HTMLElement) error.textContent = '';
    try {
      const response = await fetch(unlockForm.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({ password: new FormData(unlockForm).get('password') }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data?.error?.message || 'Das Kennwort konnte nicht geprüft werden.');
      window.location.reload();
    } catch (reason) {
      if (error instanceof HTMLElement) error.textContent = reason.message || 'Das Kennwort konnte nicht geprüft werden.';
      if (button instanceof HTMLButtonElement) button.disabled = false;
    }
  });
}

const encryptedShare = document.querySelector('[data-encrypted-share]');
const encryptedForm = document.querySelector('[data-encrypted-share-form]');
const encryptedPayload = document.querySelector('[data-encrypted-share-envelope]');
const encryptedContent = document.querySelector('[data-encrypted-share-content]');
if (
  encryptedShare instanceof HTMLElement
  && encryptedForm instanceof HTMLFormElement
  && encryptedPayload instanceof HTMLScriptElement
  && encryptedContent instanceof HTMLElement
) {
  encryptedForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = encryptedForm.querySelector('button[type="submit"]');
    const error = encryptedForm.querySelector('[data-encrypted-share-error]');
    const password = new FormData(encryptedForm).get('password');
    if (button instanceof HTMLButtonElement) button.disabled = true;
    if (error instanceof HTMLElement) error.textContent = '';
    try {
      const pageId = encryptedShare.dataset.pageId;
      const envelope = JSON.parse(encryptedPayload.textContent || 'null');
      validateEnvelope(envelope, pageId);
      const unlocked = await decryptEnvelope(envelope, String(password || ''), pageId);
      const sanitized = sanitizeNoteDoc(unlocked.document);
      createEditor({ element: encryptedContent, content: sanitized.doc, editable: false });
      encryptedContent.classList.remove('hidden');
      encryptedShare.remove();
    } catch {
      if (error instanceof HTMLElement) error.textContent = 'Kennwort falsch oder Notiz beschädigt.';
      if (button instanceof HTMLButtonElement) button.disabled = false;
    } finally {
      const input = encryptedForm.elements.namedItem('password');
      if (input instanceof HTMLInputElement) input.value = '';
    }
  });
}
