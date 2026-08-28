-- Abgewählte globale Diktier-Vorlagen: Ein Eintrag bedeutet, dass dieser
-- Nutzer diese globale Vorlage für sich ausgeblendet hat. Bewusst als
-- Abwahl statt als Zuwahl - so bleiben neu angelegte globale Vorlagen für
-- alle sichtbar, ohne dass für jeden Nutzer eine Zeile entstehen muss.
--
-- Persönliche Vorlagen brauchen das nicht: Wer seine eigene nicht mehr
-- will, löscht sie.
CREATE TABLE voice_template_optouts (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    template_id INTEGER NOT NULL REFERENCES voice_templates(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (user_id, template_id)
);

CREATE INDEX idx_voice_template_optouts_template ON voice_template_optouts(template_id);
