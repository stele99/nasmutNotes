import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.document = {
  querySelector() {
    return null;
  },
};

const { apiFetch } = await import('../../resources/js/api.js');

test('reports an empty JSON response with its HTTP status', async () => {
  globalThis.fetch = async () => new Response('', {
    status: 200,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });

  await assert.rejects(
    () => apiFetch('/api/pages'),
    (error) => error.status === 200 && /leere JSON-Antwort/.test(error.message),
  );
});

test('reports malformed JSON without leaking the parser exception', async () => {
  globalThis.fetch = async () => new Response('{', {
    status: 502,
    headers: { 'Content-Type': 'application/json' },
  });

  await assert.rejects(
    () => apiFetch('/api/pages'),
    (error) => error.status === 502 && /ungültiges JSON/.test(error.message),
  );
});

test('keeps normal JSON and no-content responses working', async () => {
  globalThis.fetch = async () => new Response('{"pages":[]}', {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
  assert.deepEqual(await apiFetch('/api/pages'), { pages: [] });

  globalThis.fetch = async () => new Response(null, { status: 204 });
  assert.equal(await apiFetch('/api/pages/1', { method: 'DELETE' }), null);
});
