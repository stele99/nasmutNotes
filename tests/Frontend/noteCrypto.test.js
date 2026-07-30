import assert from 'node:assert/strict';
import test from 'node:test';

import {
  decryptEnvelope,
  encryptDocument,
  encryptDocumentWithKey,
  rewrapEnvelope,
  validateEnvelope,
} from '../../resources/js/noteCrypto.js';

const document = {
  type: 'doc',
  content: [{
    type: 'paragraph',
    content: [{ type: 'text', text: 'Geheimnis mit Umlauten: äöü' }],
  }],
};

test('encrypts and decrypts a note entirely with WebCrypto', async () => {
  const encrypted = await encryptDocument(document, 'eine ausreichend lange passphrase', 42);
  const decrypted = await decryptEnvelope(encrypted.envelope, 'eine ausreichend lange passphrase', 42);

  assert.deepEqual(decrypted.document, document);
  assert.equal(encrypted.key.extractable, false);
});

test('uses a fresh payload IV for every save', async () => {
  const encrypted = await encryptDocument(document, 'eine ausreichend lange passphrase', 42);
  const saved = await encryptDocumentWithKey(document, encrypted.key, encrypted.envelope, 42);

  assert.notEqual(saved.payload.iv, encrypted.envelope.payload.iv);
  assert.notEqual(saved.payload.data, encrypted.envelope.payload.data);
});

test('rewrap changes only the password protected key material', async () => {
  const encrypted = await encryptDocument(document, 'eine ausreichend lange passphrase', 42);
  const rewrapped = await rewrapEnvelope(
    encrypted.envelope,
    'eine ausreichend lange passphrase',
    'eine neue ausreichend lange passphrase',
    42,
  );

  assert.deepEqual(rewrapped.envelope.payload, encrypted.envelope.payload);
  assert.notDeepEqual(rewrapped.envelope.kdf, encrypted.envelope.kdf);
  assert.notDeepEqual(rewrapped.envelope.wrapped_key, encrypted.envelope.wrapped_key);
  assert.deepEqual(
    (await decryptEnvelope(rewrapped.envelope, 'eine neue ausreichend lange passphrase', 42)).document,
    document,
  );
});

test('binds the envelope to its page', async () => {
  const encrypted = await encryptDocument(document, 'eine ausreichend lange passphrase', 42);

  assert.throws(() => validateEnvelope(encrypted.envelope, 43));
  await assert.rejects(() => decryptEnvelope(encrypted.envelope, 'eine ausreichend lange passphrase', 43));
});
