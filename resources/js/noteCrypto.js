const ITERATIONS = 600_000;
const SALT_BYTES = 16;
const IV_BYTES = 12;
const DEK_BYTES = 32;
const GCM_TAG_BITS = 128;
const MAX_ENVELOPE_BYTES = 1_000_000;
const MAX_PLAINTEXT_BYTES = 1_000_000;
const MAX_PASSWORD_BYTES = 1_024;
const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8', { fatal: true });

export class NoteCryptoFormatError extends Error {
  constructor(message) {
    super(message);
    this.name = 'NoteCryptoFormatError';
  }
}

function pageIdString(pageId) {
  const value = String(pageId);
  if (!/^[1-9][0-9]*$/.test(value)) {
    throw new NoteCryptoFormatError('Die Seiten-ID ist für die Verschlüsselung ungültig.');
  }
  return value;
}

function exactKeys(value, keys, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new NoteCryptoFormatError(`${label} ist kein Objekt.`);
  }
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (actual.length !== expected.length || actual.some((key, index) => key !== expected[index])) {
    throw new NoteCryptoFormatError(`${label} hat eine ungültige Feldstruktur.`);
  }
}

function bytesToBase64(bytes) {
  let binary = '';
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
  }
  return btoa(binary);
}

function base64ToBytes(value, label, expectedLength = null, minimumLength = null) {
  if (typeof value !== 'string' || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(value)) {
    throw new NoteCryptoFormatError(`${label} ist kein kanonisches Base64.`);
  }
  let binary;
  try {
    binary = atob(value);
  } catch {
    throw new NoteCryptoFormatError(`${label} ist kein gültiges Base64.`);
  }
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  if (bytesToBase64(bytes) !== value) {
    throw new NoteCryptoFormatError(`${label} ist kein kanonisches Base64.`);
  }
  if (expectedLength !== null && bytes.length !== expectedLength) {
    throw new NoteCryptoFormatError(`${label} hat eine ungültige Länge.`);
  }
  if (minimumLength !== null && bytes.length < minimumLength) {
    throw new NoteCryptoFormatError(`${label} ist zu kurz.`);
  }
  return bytes;
}

function aad(pageId, purpose) {
  return encoder.encode(`nasmutNotes:zk:1:page:${pageIdString(pageId)}:${purpose}`);
}

function randomBytes(length) {
  return crypto.getRandomValues(new Uint8Array(length));
}

function passwordBytes(password, enforceMinimum = false) {
  if (typeof password !== 'string') {
    throw new Error('Das Kennwort fehlt.');
  }
  const normalized = password.normalize('NFC');
  const bytes = encoder.encode(normalized);
  if (enforceMinimum && Array.from(normalized).length < 12) {
    throw new Error('Das Kennwort muss mindestens 12 Zeichen lang sein.');
  }
  if (bytes.length > MAX_PASSWORD_BYTES) {
    bytes.fill(0);
    throw new Error('Das Kennwort darf nach Unicode-Normalisierung höchstens 1.024 Byte lang sein.');
  }
  return bytes;
}

async function deriveKek(password, salt, enforceMinimum = false) {
  const passwordData = passwordBytes(password, enforceMinimum);
  try {
    const material = await crypto.subtle.importKey('raw', passwordData, 'PBKDF2', false, ['deriveKey']);
    return await crypto.subtle.deriveKey(
      { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: ITERATIONS },
      material,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );
  } finally {
    passwordData.fill(0);
  }
}

async function importDek(raw) {
  return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

async function wrapDek(rawDek, password, pageId, enforceMinimum = false) {
  const salt = randomBytes(SALT_BYTES);
  const iv = randomBytes(IV_BYTES);
  const kek = await deriveKek(password, salt, enforceMinimum);
  const data = new Uint8Array(await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv, additionalData: aad(pageId, 'wrapped_key'), tagLength: GCM_TAG_BITS },
    kek,
    rawDek,
  ));
  return {
    kdf: {
      algo: 'PBKDF2-HMAC-SHA256',
      iterations: ITERATIONS,
      salt: bytesToBase64(salt),
    },
    wrapped_key: {
      algo: 'AES-256-GCM',
      iv: bytesToBase64(iv),
      data: bytesToBase64(data),
    },
  };
}

async function unwrapDek(envelope, password, pageId) {
  const salt = base64ToBytes(envelope.kdf.salt, 'kdf.salt', SALT_BYTES);
  const iv = base64ToBytes(envelope.wrapped_key.iv, 'wrapped_key.iv', IV_BYTES);
  const wrapped = base64ToBytes(envelope.wrapped_key.data, 'wrapped_key.data', DEK_BYTES + 16);
  const kek = await deriveKek(password, salt);
  return new Uint8Array(await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv, additionalData: aad(pageId, 'wrapped_key'), tagLength: GCM_TAG_BITS },
    kek,
    wrapped,
  ));
}

async function encryptPayload(document, dek, pageId) {
  const iv = randomBytes(IV_BYTES);
  const plaintext = encoder.encode(JSON.stringify(document));
  try {
    const data = new Uint8Array(await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, additionalData: aad(pageId, 'payload'), tagLength: GCM_TAG_BITS },
      dek,
      plaintext,
    ));
    return { algo: 'AES-256-GCM', iv: bytesToBase64(iv), data: bytesToBase64(data) };
  } finally {
    plaintext.fill(0);
  }
}

export function isCryptoEnvelope(value) {
  return Boolean(value && typeof value === 'object' && value.zk === 1 && value.binding);
}

export function validateEnvelope(envelope, pageId) {
  const id = pageIdString(pageId);
  exactKeys(envelope, ['zk', 'binding', 'kdf', 'wrapped_key', 'payload'], 'Umschlag');
  exactKeys(envelope.binding, ['page_id'], 'binding');
  exactKeys(envelope.kdf, ['algo', 'iterations', 'salt'], 'kdf');
  exactKeys(envelope.wrapped_key, ['algo', 'iv', 'data'], 'wrapped_key');
  exactKeys(envelope.payload, ['algo', 'iv', 'data'], 'payload');

  if (envelope.zk !== 1 || envelope.binding.page_id !== id) {
    throw new NoteCryptoFormatError('Der Umschlag gehört nicht zu dieser Seite oder Version.');
  }
  if (envelope.kdf.algo !== 'PBKDF2-HMAC-SHA256' || envelope.kdf.iterations !== ITERATIONS) {
    throw new NoteCryptoFormatError('Die Schlüsselableitung wird nicht unterstützt.');
  }
  if (envelope.wrapped_key.algo !== 'AES-256-GCM' || envelope.payload.algo !== 'AES-256-GCM') {
    throw new NoteCryptoFormatError('Der Verschlüsselungsalgorithmus wird nicht unterstützt.');
  }
  base64ToBytes(envelope.kdf.salt, 'kdf.salt', SALT_BYTES);
  base64ToBytes(envelope.wrapped_key.iv, 'wrapped_key.iv', IV_BYTES);
  base64ToBytes(envelope.wrapped_key.data, 'wrapped_key.data', DEK_BYTES + 16);
  base64ToBytes(envelope.payload.iv, 'payload.iv', IV_BYTES);
  const payload = base64ToBytes(envelope.payload.data, 'payload.data', null, 16);
  if (payload.length > MAX_PLAINTEXT_BYTES + 16) {
    throw new NoteCryptoFormatError('Der verschlüsselte Inhalt ist zu groß.');
  }
  if (encoder.encode(JSON.stringify(envelope)).length > MAX_ENVELOPE_BYTES) {
    throw new NoteCryptoFormatError('Der Krypto-Umschlag ist zu groß.');
  }
  return envelope;
}

export function validateNewPassword(password, confirmation) {
  const normalized = typeof password === 'string' ? password.normalize('NFC') : '';
  const confirmed = typeof confirmation === 'string' ? confirmation.normalize('NFC') : '';
  const bytes = passwordBytes(password, true);
  bytes.fill(0);
  if (normalized !== confirmed) {
    throw new Error('Die Kennwörter stimmen nicht überein.');
  }
  return true;
}

export async function encryptDocument(document, password, pageId) {
  validateNewPassword(password, password);
  const rawDek = randomBytes(DEK_BYTES);
  try {
    const wrapped = await wrapDek(rawDek, password, pageId, true);
    const key = await importDek(rawDek);
    const envelope = {
      zk: 1,
      binding: { page_id: pageIdString(pageId) },
      ...wrapped,
      payload: await encryptPayload(document, key, pageId),
    };
    validateEnvelope(envelope, pageId);
    return { envelope, key };
  } finally {
    rawDek.fill(0);
  }
}

export async function decryptEnvelope(envelope, password, pageId) {
  validateEnvelope(envelope, pageId);
  let rawDek;
  let plaintext;
  try {
    rawDek = await unwrapDek(envelope, password, pageId);
    const key = await importDek(rawDek);
    const iv = base64ToBytes(envelope.payload.iv, 'payload.iv', IV_BYTES);
    const payload = base64ToBytes(envelope.payload.data, 'payload.data', null, 16);
    plaintext = new Uint8Array(await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv, additionalData: aad(pageId, 'payload'), tagLength: GCM_TAG_BITS },
      key,
      payload,
    ));
    const document = JSON.parse(decoder.decode(plaintext));
    if (!document || typeof document !== 'object' || Array.isArray(document) || document.type !== 'doc') {
      throw new NoteCryptoFormatError('Der entschlüsselte Notizinhalt ist ungültig.');
    }
    return { document, key };
  } finally {
    rawDek?.fill(0);
    plaintext?.fill(0);
  }
}

export async function encryptDocumentWithKey(document, key, envelope, pageId) {
  validateEnvelope(envelope, pageId);
  if (!key || key.type !== 'secret' || key.extractable || key.algorithm?.name !== 'AES-GCM') {
    throw new Error('Die Notiz ist nicht entsperrt.');
  }
  const updated = { ...envelope, payload: await encryptPayload(document, key, pageId) };
  validateEnvelope(updated, pageId);
  return updated;
}

export async function rewrapEnvelope(envelope, currentPassword, newPassword, pageId) {
  validateEnvelope(envelope, pageId);
  let rawDek;
  try {
    rawDek = await unwrapDek(envelope, currentPassword, pageId);
    const wrapped = await wrapDek(rawDek, newPassword, pageId, true);
    const key = await importDek(rawDek);
    const updated = { ...envelope, ...wrapped, payload: envelope.payload };
    validateEnvelope(updated, pageId);
    return { envelope: updated, key };
  } finally {
    rawDek?.fill(0);
  }
}

export const NOTE_CRYPTO_ITERATIONS = ITERATIONS;
