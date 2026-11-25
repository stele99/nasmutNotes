import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.document = { querySelector: () => null };
globalThis.window = { location: { origin: 'https://notes.example' } };

const { mcpSettingsMixin, mcpClaudeCommand, mcpJsonConfig } = await import('../../resources/js/mcpSettings.js');

test('der Klartext-Token wird beim Schließen vergessen (FR-MCP-01)', () => {
  const component = mcpSettingsMixin(1);
  component.mcpLastCreated = { id: 3, label: 'Claude', token: 'nmcp_geheim' };
  component.mcpCopied = 'token';

  component.forgetMcpSecret();

  assert.equal(component.mcpLastCreated, null);
  assert.equal(component.mcpCopied, '');
  assert.equal(component.mcpLastCommand(), '');
  assert.equal(component.mcpLastJson(), '');
});

test('Befehl und Konfiguration zeigen auf den eigenen Endpunkt', () => {
  assert.equal(
    mcpClaudeCommand('nmcp_x'),
    'claude mcp add --transport http nasmut-notes https://notes.example/mcp --header "Authorization: Bearer nmcp_x"',
  );
  const config = JSON.parse(mcpJsonConfig('nmcp_x'));
  assert.equal(config.mcpServers['nasmut-notes'].url, 'https://notes.example/mcp');
  assert.equal(config.mcpServers['nasmut-notes'].headers.Authorization, 'Bearer nmcp_x');
});
