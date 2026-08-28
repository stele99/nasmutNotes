-- Die beim Diktat verwendete Vorlage bleibt an der Notiz hängen: Ein
-- weiteres Diktat in dieselbe Notiz greift sie wieder auf, statt erneut
-- danach zu fragen - und das Modell führt die Notiz in derselben Form fort.
--
-- ON DELETE SET NULL: Löscht der Admin die Vorlage, verliert die Notiz nur
-- die Vorauswahl und fragt wieder nach; ihr Inhalt bleibt unberührt.
ALTER TABLE pages ADD COLUMN voice_template_id INTEGER
    REFERENCES voice_templates(id) ON DELETE SET NULL;
