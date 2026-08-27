-- Kosten je Modell (Euro pro 1 Mio. Tokens, Input und Output getrennt), vom
-- Admin gepflegt. Die Verrechnung geschieht zur Anzeigezeit aus diesem
-- Katalog: Nachträgliche Korrekturen bewerten auch die Historie neu.
CREATE TABLE ai_model_costs (
    model TEXT PRIMARY KEY,
    input_per_1m TEXT NOT NULL,
    output_per_1m TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'EUR',
    updated_at TEXT NOT NULL
);
