import assert from 'node:assert/strict';
import test from 'node:test';

import { noteTableOfContents } from '../../resources/js/noteToc.js';

test('builds the note table of contents from H1 and H2 headings', () => {
  const document = {
    type: 'doc',
    content: [
      { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Einleitung' }] },
      { type: 'paragraph', content: [{ type: 'text', text: 'Text' }] },
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Details' }] },
      { type: 'heading', attrs: { level: 3 }, content: [{ type: 'text', text: 'Nicht im Verzeichnis' }] },
    ],
  };

  assert.deepEqual(noteTableOfContents(document), [
    { index: 0, level: 1, text: 'Einleitung' },
    { index: 1, level: 2, text: 'Details' },
  ]);
});

test('normalizes heading text and keeps duplicate headings addressable', () => {
  const document = {
    type: 'doc',
    content: [
      {
        type: 'heading',
        attrs: { level: 1 },
        content: [
          { type: 'text', text: '  Wiederholte' },
          { type: 'hardBreak' },
          { type: 'text', text: 'Überschrift  ' },
        ],
      },
      { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Wiederholte Überschrift' }] },
    ],
  };

  assert.deepEqual(noteTableOfContents(document), [
    { index: 0, level: 1, text: 'Wiederholte Überschrift' },
    { index: 1, level: 1, text: 'Wiederholte Überschrift' },
  ]);
});

test('omits empty headings while preserving later DOM positions', () => {
  const document = {
    type: 'doc',
    content: [
      { type: 'heading', attrs: { level: 1 } },
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Sichtbar' }] },
    ],
  };

  assert.deepEqual(noteTableOfContents(document), [
    { index: 1, level: 2, text: 'Sichtbar' },
  ]);
});
