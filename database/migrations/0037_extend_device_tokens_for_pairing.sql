-- Desktop-Assistant (Paarung nach dem Muster von WhatsApp/Nextcloud): Die
-- Token-Tabelle bekommt Herkunft und Identität des Geräts. Manuell erzeugte
-- Automations-Token (NotesVoice) bleiben unverändert "manual"; gepaarte
-- Desktop-Clients tragen ihre vom Client selbst generierte, stabile ID, damit
-- ein erneutes Paarung den bestehenden Token rotiert statt zu vervielfachen.
ALTER TABLE device_tokens ADD COLUMN source TEXT NOT NULL DEFAULT 'manual';
ALTER TABLE device_tokens ADD COLUMN client_id TEXT;
ALTER TABLE device_tokens ADD COLUMN platform TEXT;

-- Der Index schützt nur aktive Token: Ein getrennter Client behält seine
-- client_id in der Historie, ein neues Pairing mit derselben ID darf aber
-- einen frischen Token anlegen.
CREATE UNIQUE INDEX idx_device_tokens_client ON device_tokens(client_id) WHERE client_id IS NOT NULL AND revoked_at IS NULL;
