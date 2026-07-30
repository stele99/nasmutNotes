-- Ausgeblendete Notizbücher verschwinden nur aus der Notizbuchliste. Ihre
-- Seiten bleiben auffindbar und stehen weiter unter „Alle Notizen" - deshalb
-- hängt das Kennzeichen am Notizbuch und nicht an seinen Seiten.
ALTER TABLE notebooks ADD COLUMN is_hidden INTEGER NOT NULL DEFAULT 0;
