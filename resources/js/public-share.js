import '../css/app.css';

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
