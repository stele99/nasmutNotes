-- KI-Verbrauchsbuch (Desktop-Assistant, Sprachnotizen, Notiz-KI): je
-- Anbieter-Aufruf ein Eintrag mit Tokenzahlen. Bewusst ohne Nachrichteninhalt
-- - es wird nur verrechnet, nichts mitgeschrieben. Fehlt die Usage-Angabe des
-- Anbieters, wird grob aus der Textlänge geschätzt (estimated = 1).
CREATE TABLE ai_usage_log (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    feature TEXT NOT NULL,
    model TEXT NOT NULL,
    prompt_tokens INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens INTEGER NOT NULL DEFAULT 0,
    estimated INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_ai_usage_user_time ON ai_usage_log(user_id, created_at);
CREATE INDEX idx_ai_usage_model_time ON ai_usage_log(model, created_at);
