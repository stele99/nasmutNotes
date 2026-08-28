# Umsetzungsplan: Overlay fuer Sprachaufnahmen

## Status

- Planungsstand: bereit zur Umsetzung
- Betrifft: Browserbasierte Sprachaufnahme, Transkription und Notizspeicherung
- Nicht betroffen: natives iOS-Aufnahme-Overlay von NotesVoice
- Neue Abhaengigkeiten: keine
- Backend-Erweiterung: `POST /api/voice/notes` erhaelt das optionale Formularfeld
  `notebook_id`; keine neue Route, keine Migration, Response unveraendert

## 1. Ziel

Die bestehende Sprachaufnahme fuer Notizen soll innerhalb des bereits vorhandenen
Overlay-/Popup-Musters bedient werden. Das Popup muss den gesamten Lebenszyklus
sichtbar begleiten:

1. Mikrofon wird vorbereitet.
2. Aufnahme laeuft.
3. Aufnahme ist pausiert und kann fortgesetzt werden.
4. Aufnahme wird beendet und transkribiert.
5. Der umgewandelte Inhalt wird als neue Notiz oder in der offenen Notiz gespeichert.
6. Erst nach bestaetigter Speicherung schliesst sich das Overlay automatisch.

Bei einem Fehler bleibt das Popup mit der bestehenden Fehleranzeige geoeffnet. Ein
laufender Transkriptions- oder Speichervorgang darf nicht versehentlich durch Escape,
Backdrop-Klick oder einen Schliessen-Schalter ausgeblendet werden.

Zusaetzlich gilt fuer neue Sprachnotizen: Wird die Aufnahme aus einem geoeffneten
Notizbuch heraus gestartet, entsteht die Notiz in genau diesem Notizbuch - nicht
neutral ohne Zuordnung. Ohne gewaehltes Notizbuch bleibt es bei der bestehenden
Ableitung aus dem Inhalt (FR-VOICE-04).

## 2. Abgrenzung

### 2.1 Im Umfang

- Neue Sprachnotiz aus der Workspace-Uebersicht.
- Neue Sprachnotiz aus der Seitenleiste.
- Notizbuchbindung: Eine aus einem geoeffneten Notizbuch gestartete Sprachnotiz
  entsteht in genau diesem Notizbuch.
- Diktat in eine bereits geoeffnete, unverschluesselte Notiz.
- Aufnahme, Pause, Fortsetzen, Beenden und Verwerfen ueber den vorhandenen
  `MediaRecorder`-Fluss.
- Bestehende Transkriptions-, Fehler-, Hinweis- und Speicherzustaende.
- Bestehendes Alpine-CSP-, Dialog-, Fokus- und Icon-Muster.
- Aufgaben- und Logbuchdiktat bleiben funktional kompatibel mit dem gemeinsam
  genutzten Recorder und koennen Pause/Fortsetzen ebenfalls verwenden.

### 2.2 Ausserhalb des Umfangs

- Keine neuen Libraries.
- Keine neue Modal-, Store- oder Event-Architektur.
- Keine Aenderung an Slim, Alpine, TipTap, SQLite oder Vite.
- Keine neuen Endpunkte. Einzige Vertragsaenderung ist das optionale Formularfeld
  `notebook_id` an `POST /api/voice/notes`; alle Response-Koerper bleiben
  unveraendert.
- Keine Datenbankmigration.
- Kein Umbau des Offline-/Outbox-Systems.
- Kein Wiederholungsmechanismus fuer eine bereits verbrauchte Audiodatei.
- Keine Aenderung am nativen Aufnahme-Overlay der iOS-Kurzbefehle-App. Dieses
  Overlay wird vom Betriebssystem bereitgestellt und ist aus der Webanwendung
  nicht steuerbar.

## 3. Relevanter Ist-Zustand

| Bereich | Datei | Aktuelles Verhalten |
|---|---|---|
| Recorder | `resources/js/voice.js:79-157` | `MediaRecorder` kann nur gestartet, beendet oder verworfen werden. |
| Voice-Zustand | `resources/js/voice.js:164-273` | Kennt `idle`, `recording` und `processing`. |
| Aufnahmeanzeige | `resources/views/partials/voice_panel.php` | Inline-Panel mit Dauer, `Fertig`, `Verwerfen`, Verarbeitung, Fehler und Hinweis. |
| Vorlagen-Popup | `resources/views/partials/voice_template_picker.php` | Bereits teleportierter modaler Dialog mit Backdrop, Escape und Fokusfalle. |
| Neue Sprachnotiz | `resources/js/pageList.js:435-452` | `POST /api/voice/notes`; die Antwort kommt erst nach dem serverseitigen Speichern. |
| Notizbuchwahl | `resources/js/pageList.js:402-428` | `createPage()` sendet `notebook_id` der gewaehlten Sammlung mit (Zeile 420); `handleVoiceRecording()` sendet keins, das Notizbuch leitet das Modell ab. |
| Diktat in Notiz | `resources/js/notePage.js:1571-1624` | `POST /api/voice/transcribe`, Einfuegen in TipTap, danach normaler debouncter Autosave. |
| Notiz-Autosave | `resources/js/notePage.js:1067-1152` | Speichert zuerst lokal und synchronisiert ueber die bestehende Outbox. |
| Speicheranzeige | `resources/js/notePage.js:1512-1525` | `Laedt`, `Gespeichert`, `Speichern`, `Nicht gespeichert`, `offline`, `invalid`, `conflict`. |
| Voice-Stile | `resources/css/app.css:901-949` | Aufnahmefarbe, pulsierender Punkt und Panel-Rahmen. |
| Icons | `resources/js/icons.js` | Lucide ist vorhanden; Mikrofon und Stop-Symbol sind registriert. |

Wesentliche Konsequenz: Die beiden Notizwege haben unterschiedliche
Erfolgsschwellen.

- Bei `POST /api/voice/notes` ist eine erfolgreiche `201`-Antwort bereits der
  Nachweis, dass die neue Notiz serverseitig gespeichert wurde.
- Bei `POST /api/voice/transcribe` bedeutet die erfolgreiche `200`-Antwort nur,
  dass der Text erzeugt wurde. Das Popup darf erst nach dem anschliessenden
  erfolgreichen Notiz-Autosave schliessen.
- Die manuelle Neuanlage respektiert die gewaehlte Sammlung (`notebook_id`,
  `resources/js/pageList.js:420`); der Voice-Weg ignoriert sie heute - das
  Notizbuch kommt allein aus der Model-Ableitung
  (`app/Domain/Voice/VoiceNoteService.php:830`, `matchNotebook()`).

## 4. Ziel-Zustandsmodell

Der bestehende `voiceStatus` wird gezielt erweitert, aber nicht durch einen neuen
globalen Store ersetzt.

| Zustand | Bedeutung | Sichtbare UI | Erlaubte Aktionen |
|---|---|---|---|
| `idle` | Kein aktiver Voice-Vorgang | Kein Aufnahme-Popup | Neue Aufnahme starten |
| `starting` | Mikrofonfreigabe und Recorder-Aufbau laufen | `Mikrofon wird vorbereitet…` | Keine Mehrfachstarts |
| `recording` | Audio wird aktiv aufgezeichnet | `Aufnahme läuft`, Dauer, Restzeit | Pause, Fertig, Verwerfen |
| `paused` | Recorder ist pausiert | `Aufnahme pausiert`, eingefrorene Dauer | Fortsetzen, Fertig, Verwerfen |
| `processing` | Blob wird finalisiert, hochgeladen und transkribiert | Bestehende Transkriptionsanzeige | Keine Schliess- oder Aufnahmeaktion |
| `saving` | Transkript wurde in die offene Notiz eingesetzt und wird gespeichert | `Notiz wird gespeichert…` | Keine Schliess- oder Aufnahmeaktion |

Fehler bleiben wie bisher getrennt in `voiceError`. Dadurch ist kein zusaetzlicher
Fehlerzustand erforderlich. Nach einem Fehler wird `voiceStatus` auf `idle` gesetzt,
aber das Popup bleibt wegen `voiceError` sichtbar.

### 4.1 Erlaubte Uebergaenge

```text
idle
  -> starting
  -> recording
  -> paused
  -> recording
  -> processing
  -> saving       nur beim Diktat in eine offene Notiz
  -> idle         nur nach erfolgreichem Abschluss
```

Zusaetzliche Uebergaenge:

```text
recording -> processing     Fertig
paused    -> processing     Fertig
recording -> idle           Verwerfen
paused    -> idle           Verwerfen
starting  -> idle + error   Mikrofon-/Berechtigungsfehler
processing/saving -> idle + error
```

## 5. Umsetzungsschritte

### 5.1 Low-Level-Recorder um Pause und Fortsetzen erweitern

Datei: `resources/js/voice.js`

- [ ] Rueckgabetyp und JSDoc von `startVoiceRecording()` um `pause()` und
  `resume()` erweitern.
- [ ] `pause()` nur ausfuehren, wenn `MediaRecorder.state === 'recording'` ist.
- [ ] `resume()` nur ausfuehren, wenn `MediaRecorder.state === 'paused'` ist.
- [ ] `stop()` aus `paused` funktioniert bereits heute: Der Controller prueft nur
  `recorder.state !== 'inactive'` (`resources/js/voice.js:129`). Dieses Verhalten
  nicht einengen und mit einem Test sichern. Die eigentliche Sperre steht im Mixin
  (`stopVoice()` lehnt alles ausser `recording` ab,
  `resources/js/voice.js:206-209`) und wird in Abschnitt 5.3 geoeffnet.
- [ ] `cancel()` weiterhin aus jedem aktiven Zustand tolerieren und alle
  Stream-Tracks freigeben.
- [ ] Native Zustandsfehler abfangen und als verstaendliche Voice-Fehler an den
  bestehenden Mixin-Fluss weiterreichen.
- [ ] Keine reaktive Alpine-Huelle um `MediaRecorder` oder `MediaStream` legen;
  der Controller bleibt wie bisher in der Closure des Mixins.

### 5.2 Nur aktive Aufnahmezeit zaehlen

Datei: `resources/js/voice.js`

Die bisherige Berechnung `Date.now() - startedAt` zaehlt Pausen mit. Sie wird durch
eine segmentbasierte aktive Laufzeit ersetzt.

Vorgesehene interne Werte:

- `recordedMilliseconds`: bereits abgeschlossene aktive Aufnahmezeit.
- `segmentStartedAt`: Beginn des aktuell laufenden Aufnahmeabschnitts.
- `currentRecordedMilliseconds()`: Summe aus abgeschlossenen Segmenten und dem
  gerade laufenden Segment.

Verhalten:

- [ ] Beim Start beginnt das erste aktive Segment.
- [ ] Beim Pausieren wird die Laufzeit des Segments zu
  `recordedMilliseconds` addiert.
- [ ] Waehrend der Pause bleibt `voiceSeconds` unveraendert.
- [ ] Beim Fortsetzen beginnt ein neues aktives Segment.
- [ ] Das automatische Zeitlimit basiert ausschliesslich auf aktiver
  Aufnahmezeit.
- [ ] `recording.seconds` enthaelt ebenfalls nur aktive Aufnahmezeit.
- [ ] `Fertig` aus dem Pausenzustand darf keine Pausendauer hinzurechnen.

Der Mikrofonstream bleibt waehrend einer Pause geoeffnet. Das entspricht dem
nativen Verhalten von `MediaRecorder.pause()` und vermeidet einen neuen
Segment-/Stream-Zusammenfuehrungsmechanismus.

### 5.3 `voiceRecorderMixin()` erweitern

Datei: `resources/js/voice.js`

- [ ] Kommentar und erlaubte Werte von `voiceStatus` aktualisieren.
- [ ] `startVoice()` setzt `starting` vor dem ersten `await`, damit ein
  Doppelklick keine parallelen Mikrofonanfragen startet.
- [ ] Nach erfolgreichem Recorder-Aufbau auf `recording` wechseln.
- [ ] Bei Startfehlern auf `idle` zurueckwechseln und `voiceError` setzen.
- [ ] `pauseVoice()` hinzufuegen und erst nach erfolgreichem nativen Pause-Aufruf
  auf `paused` wechseln.
- [ ] `resumeVoice()` hinzufuegen und erst nach erfolgreichem nativen
  Resume-Aufruf auf `recording` wechseln.
- [ ] `stopVoice()` fuer `recording` und `paused` freigeben.
- [ ] Finalisierung des Recorders, Mindestgroessenpruefung und
  `handleVoiceRecording()` in einem gemeinsamen `try/catch/finally` absichern.
- [ ] Bei erfolgreichem `handleVoiceRecording()` auf `idle` wechseln.
- [ ] Bei Fehlern `voiceError` setzen, auf `idle` wechseln und den Fehlerdialog
  sichtbar lassen.
- [ ] `cancelVoice()` fuer `recording`, `paused` und einen abgebrochenen Start
  sicher machen.
- [ ] Eine veraltete, nach Komponentenabbau abgeschlossene
  `getUserMedia()`-Anfrage erkennen und ihren Stream sofort wieder schliessen.
- [ ] `isVoiceBusy()` unveraendert als `voiceStatus !== 'idle'` verwenden.
- [ ] Kleine Statushelfer nach dem bestehenden Muster von `voiceTimeLabel()` und
  `voiceRemainingLabel()` bereitstellen, statt komplexe CSP-Ausdruecke in die
  PHP-Templates zu schreiben.

Vorgesehene Statusbeschriftungen:

| Zustand | Text |
|---|---|
| `starting` | `Mikrofon wird vorbereitet…` |
| `recording` | `Aufnahme läuft` |
| `paused` | `Aufnahme pausiert` |
| `processing` | `Die Aufnahme wird transkribiert und aufbereitet…` |
| `saving` | `Notiz wird gespeichert…` |

### 5.4 Vorhandenes Voice-Popup zum Aufnahme-Overlay erweitern

Datei: `resources/views/partials/voice_template_picker.php`

Das vorhandene Popup bleibt die einzige modale Huelle fuer den Notiz-Voice-Fluss.
Es wird nicht durch eine neue allgemeine Modal-Komponente ersetzt.

- [ ] Bestehendes `x-teleport="body"`, Backdrop, `z-[70]`, `role="dialog"`,
  `aria-modal="true"` und die globale Fokusfalle beibehalten.
- [ ] Die Sichtbarkeit nicht mehr nur an `voiceTemplatePickerOpen`, sondern an
  eine Methode wie `isVoiceDialogOpen()` binden.
- [ ] `isVoiceDialogOpen()` liefert `true`, wenn die Vorlagenauswahl offen ist,
  ein Voice-Vorgang laeuft oder `voiceError` vorhanden ist.
- [ ] Den Dialogtitel ueber eine einfache Mixin-Methode an Vorlagenauswahl,
  Aufnahme, Pause und Verarbeitung anpassen.
- [ ] Wegen mehrfacher Einbindung weiterhin `aria-label` beziehungsweise eine
  dynamische Beschriftung ohne feste, doppelte ID verwenden.
- [ ] Vorlagenauswahl nur zeigen, solange `voiceTemplatePickerOpen` aktiv ist.
- [ ] Nach Bestaetigung der Vorlage im selben Dialog auf `starting` und danach
  auf die Aufnahmeanzeige wechseln.
- [ ] Den Zustandswechsel in demselben Event-Turn vornehmen, damit Backdrop und
  Popup zwischen Auswahl und Recorder nicht sichtbar verschwinden.
- [ ] Das bestehende `voice_panel.php` fuer Aufnahme, Pause, Verarbeitung und
  Fehler innerhalb der modalen Huelle wiederverwenden.
- [ ] `voiceNotice` ausserhalb der modalen Huelle als vorhandenen
  nicht-blockierenden Statushinweis rendern. Dadurch kann das Popup nach Erfolg
  schliessen, ohne die bestehende Erfolgsmeldung zu verlieren. Die Anzeigestelle
  in den Notizkontexten regelt Abschnitt 5.7 (Partiale `voice_status.php`).

### 5.5 Schliessen des Dialogs absichern

Dateien:

- `resources/js/voice.js`
- `resources/views/partials/voice_template_picker.php`

- [ ] Eine zentrale Methode wie `closeVoiceDialog()` verwenden.
- [ ] Bei `starting`, `recording`, `paused`, `processing` und `saving` ignoriert
  diese Methode Backdrop-Klick und Escape.
- [ ] Waehrend Aufnahme und Pause ist `Verwerfen` der einzige Abbruchweg.
- [ ] Waehrend Transkription und Speicherung gibt es keinen Abbruchschalter, da
  der Servervorgang bereits laeuft und aktuell kein `AbortController`-Muster
  existiert.
- [ ] Bei einem Fehler darf der Nutzer den Dialog explizit schliessen; dabei wird
  die bestehende Methode `dismissVoiceError()` verwendet.
- [ ] Nach Erfolg schliesst der Dialog ohne zusaetzlichen Klick, weil
  `voiceStatus` erst dann auf `idle` gesetzt wird.

### 5.6 Aufnahmesteuerung im vorhandenen Panel ergaenzen

Datei: `resources/views/partials/voice_panel.php`

Aufnahmezustand:

- [ ] Sichtbarer Text `Aufnahme läuft` mit `role="status"` beziehungsweise
  geeigneter Live-Region.
- [ ] Pulsierender roter Punkt nur waehrend aktiver Aufnahme.
- [ ] Aktive Aufnahmezeit und vorhandene Restzeitanzeige beibehalten.
- [ ] Schalter `Pause`, `Fertig` und `Verwerfen` anzeigen.

Pausenzustand:

- [ ] Text `Aufnahme pausiert` anzeigen.
- [ ] Zeit ohne Pulsanimation eingefroren anzeigen.
- [ ] Schalter `Fortsetzen`, `Fertig` und `Verwerfen` anzeigen.

Verarbeitung und Speicherung:

- [ ] Bestehenden Transkriptionshinweis fuer `processing` beibehalten.
- [ ] Speicherhinweis fuer `saving` ergaenzen.
- [ ] Alle Aufnahmeaktionen ausblenden oder deaktivieren.

Fehler und Hinweise:

- [ ] `voiceError` weiterhin mit `role="alert"` und vorhandener Gefahrenfarbe
  darstellen.
- [ ] Im modalen Fehlerzustand einen expliziten Schliessen-Schalter anbieten.
- [ ] `voiceNotice` weiterhin mit `role="status"` darstellen, fuer die
  Notiz-Popup-Kontexte jedoch ausserhalb des geschlossenen Dialogs.

### 5.7 Einbindungen fuer Notizfluesse bereinigen

Dateien:

- `resources/views/app.php`
- `resources/views/partials/sidebar.php`
- `resources/views/page_note.php`

- [ ] Die separate Inline-Einbindung von `voice_panel.php` in diesen drei
  Notizkontexten entfernen.
- [ ] Ersatz fuer Hinweis und Fehler schaffen: `voiceNotice` und `voiceError`
  werden heute in `voice_panel.php` selbst gerendert (Zeilen 19-20), unter anderem
  der Erfolgshinweis aus `pageList.showVoiceNotice()` mit seinem 8-Sekunden-Timer.
  Ohne Inline-Panel haetten sie in den drei Notizkontexten keine Anzeigestelle.
  Deshalb eine auf Fehler und Hinweis begrenzte Inline-Anzeige beibehalten - etwa
  ein kleines eigenes Partial nach dem bestehenden Partial-Muster - waehrend die
  Aufnahme- und Verarbeitungsinhalte in den Dialog wandern.
- [ ] `voice_template_picker.php` beibehalten; es enthaelt kuenftig sowohl
  Vorlagenauswahl als auch Aufnahmepopup.
- [ ] Leere Wrapper mit `mt-*`/`mb-*` entfernen, damit nach dem Teleportieren
  kein ungewollter Abstand im Seitenlayout bleibt.
- [ ] Vorhandenes serverseitiges Feature-Gating ueber `$voiceEnabled`
  beibehalten.
- [ ] Vorhandenes clientseitiges Gating ueber `voiceSupported`, Schreibrecht und
  Verschluesselungszustand beibehalten.

Die direkten Einbindungen von `voice_panel.php` in `page_task.php` und
`page_log.php` bleiben bestehen. Dadurch werden Aufgaben- und Logbuchdiktat nicht
in den Notiz-Vorlagenpopup gezogen und ihre bestehende Seitenstruktur bleibt
unveraendert.

### 5.8 Neue Sprachnotiz im aktuellen Notizbuch anlegen und bis zur gespeicherten Seite offen halten

Dateien:

- `resources/js/pageList.js`
- `app/Controllers/VoiceNoteController.php`
- `app/Domain/Voice/VoiceNoteService.php`

Der bestehende Ablauf in `handleVoiceRecording()` ist bereits korrekt
await-basiert:

1. `voiceFormData()` erstellen.
2. `template_id` und optionalen Aufnahmeort ergaenzen.
3. `POST /api/voice/notes` abwarten.
4. Erst nach erfolgreicher `201`-Antwort Seitenlisten aktualisieren und zur neuen
   Seite navigieren.

Notizbuchbindung:

Heute ignoriert der Voice-Weg die gewaehlte Sammlung: `createPage()` sendet
`notebook_id` der aktiven Notizbuchsammlung mit (`resources/js/pageList.js:420`),
`handleVoiceRecording()` dagegen nicht - das Notizbuch leitet allein das Modell
aus dem Inhalt ab (`app/Domain/Voice/VoiceNoteService.php:830`). Kuenftig gilt:
Ist ein Notizbuch ausgewaehlt, wird die Sprachnotiz dort angelegt.

- [ ] In `handleVoiceRecording()` `notebook_id` als Formularfeld mitsenden, wenn
  `activeCollection === 'notebook'` und `activeNotebookId` gesetzt ist - dieselbe
  Bedingung wie in `createPage()` (`resources/js/pageList.js:420`) und
  `refresh()` (`resources/js/pageList.js:316-317`).
- [ ] Ohne gewaehltes Notizbuch (Startseite, Favoriten, Geteilt, Papierkorb) kein
  Feld senden; das bisherige Modellverfahren bleibt dann unveraendert.
- [ ] Beide Einbindungen von `pageList` (Uebersicht und Seitenleiste) nutzen ihren
  jeweiligen eigenen `activeCollection`-Zustand, genau wie bei `createPage()`.
- [ ] `VoiceNoteController::store()` liest das optionale Formularfeld
  `notebook_id` ueber einen neuen privaten Helfer nach dem Muster von
  `optionalTemplateId()` (`app/Controllers/VoiceNoteController.php:193-201`) und
  reicht es an `createNote()` weiter. Unbrauchbare Werte werden wie dort als
  `null` gewertet.
- [ ] `VoiceNoteService::createNote()` erhaelt einen zusaetzlichen Parameter
  `?int $notebookId = null`. Er hat Vorrang vor der Model-Ableitung: Anstelle der
  heutigen Zeile (`app/Domain/Voice/VoiceNoteService.php:530`) wird
  `$notebookId ?? $result['notebook_id']` an `PageService::create()` uebergeben.
- [ ] Existenz und Besitz des Notizbuchs prueft weiterhin `PageService::create()`
  (`app/Domain/PageService.php:61-94`): Ein fremdes oder inzwischen geloeschtes
  Notizbuch wirft wie bei der manuellen Anlage eine `ValidationException`, der
  vorhandene Guard macht daraus HTTP 422.
- [ ] Geteilte Notizbuecher brauchen keine Sonderbehandlung: `PageService::create()`
  klaert sie bereits selbst ab (`sharedNotebookFor()`).
- [ ] Der Response-Vertrag bleibt unveraendert; `page.notebook_id` wird bereits
  serialisiert (`app/Controllers/VoiceNoteController.php:117`).
- [ ] `docs/URS.md` FR-VOICE-04 (Zeile 379) um den Vorrang der gewaehlten Sammlung
  ergaenzen: Die Ableitung aus dem Inhalt greift nur noch, wenn kein Notizbuch
  ausgewaehlt ist.

Umsetzung (Overlay-Verhalten):

- [ ] Keine neue Speicheranfrage und kein Polling einfuehren.
- [ ] Sicherstellen, dass `stopVoice()` waehrend des gesamten
  `handleVoiceRecording()` im Zustand `processing` bleibt.
- [ ] Das Popup erst durch den erfolgreichen Abschluss beziehungsweise die
  anschliessende Navigation verschwinden lassen.
- [ ] Bestehende `voiceNotice`-Meldung nach dem Schliessen ausserhalb des Dialogs
  weiter anzeigen; sie nennt das Notizbuch bereits aus der Antwort.
- [ ] Fehler von `apiFetch()` unveraendert in `voiceError` uebernehmen; Popup
  bleibt offen.

Fuer Inhalt, Vorlage und Audit-Eintrag bleibt `VoiceNoteService::createNote()`
massgeblich: Sie speichern alles vor der `201`-Antwort.

### 5.9 Diktat in eine offene Notiz bis zum Autosave offen halten

Datei: `resources/js/notePage.js`

Dies ist die wesentliche funktionale Aenderung am Speicherfluss. Der aktuelle
Handler endet direkt nach dem Einfuegen in TipTap. Kuenftig muss er die bestehende
Speicherpipeline abwarten.

Vorgesehene Reihenfolge in `handleVoiceRecording()`:

1. Schreibrecht, Editor und Verschluesselungszustand wie bisher pruefen.
2. `POST /api/voice/transcribe` abwarten.
3. Leeres Ergebnis weiterhin ablehnen.
4. Verwendete Voice-Vorlage wie bisher an den lokalen Zustand uebernehmen.
5. Einen eventuell bereits laufenden `pendingSave` nach dem bestehenden
   50-ms-Wartemuster abwarten.
6. Transkribierte Knoten an der gespeicherten Editorposition einfuegen.
7. `voiceStatus` auf `saving` setzen.
8. Den durch `onChange()` gesetzten Debounce ueber den vorhandenen `saveNow()`-
   Weg sofort ausfuehren.
9. Nach `saveNow()` explizit pruefen, ob `status === 'saved'` ist.
10. Zusaetzlich mit `hasQueuedNoteChange(pageId)` sicherstellen, dass kein
    unuebertragener Outbox-Eintrag verbleibt.
11. Einen angewendeten Titelvorschlag ebenfalls abwarten.
12. Erst danach aus `handleVoiceRecording()` erfolgreich zurueckkehren.

Wichtige Details:

- [ ] Nicht direkt `PUT /api/pages/{id}/content` aufrufen. Die bestehende
  Offline-/Outbox-Pipeline bleibt der einzige Speicherweg.
- [ ] Keinen zusaetzlichen Autosave-Mechanismus einfuehren.
- [ ] Kein `forceSnapshot` setzen, sofern der bestehende normale Autosave dies
  nicht bereits verlangt.
- [ ] Beachten, dass `saveNow()` interne Fehler aktuell in `status` und
  `saveError` umsetzt, statt sie immer zu werfen.
- [ ] Nach dem Await deshalb den Status ausdruecklich validieren und bei
  `offline`, `invalid`, `conflict`, verbleibendem `saving` oder offener Outbox
  einen Fehler an den Voice-Fluss weitergeben.
- [ ] Fuer die Fehlermeldung bevorzugt das vorhandene `saveError` und die
  bestehende Statussemantik verwenden.
- [ ] Lokal gequeue-ter Inhalt gilt fuer diese Anforderung nicht als erfolgreich
  serverseitig gespeichert.

### 5.10 Titelvorschlag abwartbar machen

Datei: `resources/js/notePage.js`

- [ ] `applyVoiceTitle()` in eine asynchrone, abwartbare Methode umstellen.
- [ ] Weiterhin nur einen leeren Titel beziehungsweise `Neue Notiz` ersetzen.
- [ ] Selbst vergebene Titel unveraendert lassen.
- [ ] Falls ein Vorschlag angewendet wird, den vorhandenen `savePageTitle()`-
  Request abwarten.
- [ ] Ein Fehler beim Titel-PATCH verhindert den automatischen Abschluss des
  Voice-Popups und wird ueber den bestehenden Voice-Fehlerpfad sichtbar.

Inhalt und Titel bleiben zwei vorhandene, getrennte Speicheroperationen. Es wird
keine neue Transaktion oder Backend-Kopplung eingefuehrt.

### 5.11 Trigger konsistent halten

Dateien:

- `resources/views/app.php`
- `resources/views/partials/sidebar.php`
- `resources/views/page_note.php`
- `resources/views/page_task.php`
- `resources/views/page_log.php`

- [ ] Startschalter waehrend `isVoiceBusy()` deaktivieren, soweit sie nicht
  bereits durch das modale Overlay unzugreifbar sind.
- [ ] Beenden nicht mehr vom verdeckten Hauptschalter abhaengig machen; die
  verbindliche Aktion steht im Voice-Panel.
- [ ] Bestehende Berechtigungs-, Offline- und Verschluesselungspruefungen
  beibehalten.
- [ ] Keine komplexen Inline-Ausdruecke hinzufuegen, die mit dem Alpine-CSP-Build
  unvereinbar sind.

### 5.12 Icons und Styles minimal ergaenzen

Dateien:

- `resources/js/icons.js`
- `resources/css/app.css`

- [ ] `Pause` und `Play` aus der bereits installierten Lucide-Abhaengigkeit
  registrieren.
- [ ] Keine neue Icon-Bibliothek oder eigenen SVG-Mechanismus einfuehren.
- [ ] Bestehende Button-Klassen `btn`, `btn-primary`, `btn-quiet` und
  `icon-action` verwenden.
- [ ] Das bestehende Modal-Layout des Vorlagenpickers fuer Breite, Hintergrund,
  Rahmen und Schatten beibehalten.
- [ ] `.voice-panel` innerhalb des Dialogs nur soweit noetig anpassen, damit
  keine doppelten Rahmen oder unpassenden Aussenabstaende entstehen.
- [ ] Den pausierten Zustand ohne Pulsanimation darstellen.
- [ ] Bestehende `prefers-reduced-motion`-Regel erhalten.
- [ ] Darstellung auf schmalen Bildschirmen mit umbruchfaehigen Schaltern
  pruefen.

## 6. Geplante Dateiaenderungen

| Datei | Geplante Aenderung |
|---|---|
| `resources/js/voice.js` | Native Pause/Resume-Steuerung, aktive Zeitmessung, neue Voice-Zustaende und Dialoghelfer. |
| `resources/views/partials/voice_template_picker.php` | Bestehendes Popup ueber Auswahl, Aufnahme, Verarbeitung, Speicherung und Fehler offen halten. |
| `resources/views/partials/voice_panel.php` | Status `Aufnahme läuft`, Pause/Fortsetzen, Stop aus Pause und Speicheranzeige. |
| `resources/js/notePage.js` | Autosave und gegebenenfalls Titel-PATCH vor erfolgreichem Voice-Abschluss abwarten. |
| `resources/js/pageList.js` | `notebook_id` der gewaehlten Sammlung mitsenden (Spiegel von `createPage()`). |
| `app/Controllers/VoiceNoteController.php` | Optionales Formularfeld `notebook_id` parsen und an den Service durchreichen. |
| `app/Domain/Voice/VoiceNoteService.php` | `createNote()` um `?int $notebookId` erweitern; gewaehltes Notizbuch vor die Model-Ableitung stellen. |
| `docs/URS.md` | FR-VOICE-04 um den Vorrang der gewaehlten Sammlung ergaenzen. |
| `resources/views/app.php` | Doppelte Inline-Aufnahmeanzeige entfernen, vorhandenes Popup verwenden, Hinweis-/Fehleranzeige inline halten. |
| `resources/views/partials/sidebar.php` | Doppelte Inline-Aufnahmeanzeige entfernen, vorhandenes Popup verwenden, Hinweis-/Fehleranzeige inline halten. |
| `resources/views/page_note.php` | Doppelte Inline-Aufnahmeanzeige entfernen, Hinweis-/Fehleranzeige inline halten und Trigger-Busy-Zustand angleichen. |
| `resources/views/partials/voice_status.php` | Neu, klein: nur `voiceError` und `voiceNotice` ausserhalb des Dialogs (Bestandteile der bisherigen `voice_panel.php`-Zeilen 19-20). |
| `resources/views/page_task.php` | Nur notwendige Zustands-/Triggeranpassungen; bestehendes Inline-Panel bleibt. |
| `resources/views/page_log.php` | Nur notwendige Zustands-/Triggeranpassungen; bestehendes Inline-Panel bleibt. |
| `resources/js/icons.js` | Vorhandene Lucide-Registry um Pause und Play ergaenzen. |
| `resources/css/app.css` | Minimale Dialog- und Pausenstile. |
| `tests/Frontend/voice.test.js` | Neue automatisierte Recorder- und Zustandspruefungen. |

Nicht geplant sind Aenderungen an:

- `app/Config/routes.php` (keine neuen Routen)
- `app/Domain/Voice/OpenAiClient.php`
- Datenbankmigrationen
- Response-Koerpern der bestehenden Endpunkte

## 7. Automatisierte Tests

Neue Datei: `tests/Frontend/voice.test.js`

Die Tests verwenden `node:test`, `node:assert`, einen kleinen Fake fuer
`MediaRecorder`, einen Fake-MediaStream und kontrollierte Timer. Es wird keine
Testbibliothek hinzugefuegt.

### 7.1 Recorder-Controller

- [ ] Waehlt weiterhin einen unterstuetzten MIME-Typ und Dateinamen.
- [ ] Startet genau einen Recorder und einen Stream.
- [ ] `pause()` ruft die native Pause genau einmal auf.
- [ ] Mehrfaches Pausieren ist wirkungslos oder kontrolliert abgewiesen.
- [ ] `resume()` ruft das native Resume genau einmal auf.
- [ ] Mehrfaches Fortsetzen ist wirkungslos oder kontrolliert abgewiesen.
- [ ] `stop()` funktioniert aus `recording`.
- [ ] `stop()` funktioniert aus `paused`.
- [ ] `cancel()` verwirft Chunks und beendet alle Tracks.
- [ ] `stop()` beendet alle Tracks und erzeugt weiterhin genau einen Blob.

### 7.2 Zeitmessung

- [ ] Aktive Sekunden steigen waehrend `recording`.
- [ ] Sekunden bleiben waehrend `paused` unveraendert.
- [ ] Sekunden laufen nach `resume()` ohne Sprung weiter.
- [ ] Das Zeitlimit wird waehrend einer Pause nicht erreicht.
- [ ] Das Zeitlimit greift nach ausreichend aktiver Aufnahmezeit.
- [ ] Die an `voiceFormData()` uebergebene Dauer enthaelt keine Pausenzeit.

### 7.3 Mixin-Zustaende

- [ ] `startVoice()` wechselt sofort auf `starting` und danach auf `recording`.
- [ ] Mikrofonfehler wechseln auf `idle` und setzen `voiceError`.
- [ ] `pauseVoice()` wechselt auf `paused`.
- [ ] `resumeVoice()` wechselt auf `recording`.
- [ ] `stopVoice()` wechselt auf `processing`.
- [ ] Ein verzoegertes `handleVoiceRecording()` haelt den Busy-Zustand bis zur
  Promise-Aufloesung offen.
- [ ] Erfolg wechselt erst nach Promise-Aufloesung auf `idle`.
- [ ] Ein Fehler aus `handleVoiceRecording()` setzt `voiceError` und schliesst
  den Fehlerzustand nicht automatisch.
- [ ] `Verwerfen` aus Aufnahme und Pause setzt Zustand, Zeit und Meldungen
  konsistent zurueck.
- [ ] Ein zu kurzer Blob verwendet weiterhin die bestehende Fehlermeldung.

### 7.4 Bestehende Backendtests

Die vorhandenen Tests in
`tests/Integration/Domain/Voice/VoiceNoteServiceTest.php` bleiben unveraendert und
sichern weiterhin unter anderem:

- die vollstaendige Anlage einer neuen Sprachnotiz,
- die Speicherung des ProseMirror-Inhalts,
- das Verhindern leerer Notizen bei Fehlern,
- die Vorlagenbindung,
- das Diktat in eine bestehende Notiz,
- Upload- und Providerfehler.

### 7.5 Notizbuchbindung (Backend)

Erweitert `tests/Integration/Domain/Voice/VoiceNoteServiceTest.php` nach dem
vorhandenen MockHandler-Muster:

- [ ] `createNote()` mit explizitem `notebookId` legt die Seite in genau diesem
  Notizbuch an, selbst wenn das Modell ein anderes ableiten wuerde.
- [ ] Ohne `notebookId` bleibt die Model-Ableitung (FR-VOICE-04) unveraendert.
- [ ] Ein fremdes oder unbekanntes `notebookId` fuehrt zu `ValidationException`,
  ohne dass eine Seite zurueckbleibt.
- [ ] Der Controller akzeptiert `notebook_id` als Ganzzahl und als
  Ziffernfolge und wertet unbrauchbare Eingaben als `null`.
- [ ] Die `201`-Antwort enthaelt in `page.notebook_id` das gewaehlte Notizbuch.

Die Notizbuchbindung aus Abschnitt 5.8 erweitert den Request-Vertrag von
`/api/voice/notes`; dafuer sind diese Tests erforderlich. Darueber hinaus sind
neue Backendtests nur noetig, wenn die Umsetzung entgegen diesem Plan weitere
Vertraege aendert.

## 8. Manuelle Pruefmatrix

### 8.1 Neue Notiz aus der Workspace-Uebersicht

- [ ] Vorlagen-Popup oeffnet sich.
- [ ] Nach `Aufnahme starten` bleibt dieselbe modale Huelle sichtbar.
- [ ] Status lautet `Aufnahme läuft`.
- [ ] Pause friert Zeit und Restzeit ein.
- [ ] Fortsetzen laesst die Zeit weiterlaufen.
- [ ] `Fertig` funktioniert aus Aufnahme und Pause.
- [ ] Transkriptions-/Speicheranzeige bleibt bis zur `201`-Antwort sichtbar.
- [ ] Erst danach erfolgt die Navigation zur gespeicherten Notiz.
- [ ] Ist in der Uebersicht ein Notizbuch geoeffnet, landet die Sprachnotiz in
  genau diesem Notizbuch - auch wenn der Inhalt ein anderes nahelegt.
- [ ] Von der Startseite aus bleibt es bei der Ableitung aus dem Inhalt.
- [ ] Der Erfolgshinweis nennt das Notizbuch der neuen Notiz.

### 8.2 Neue Notiz aus der Seitenleiste

- [ ] Verhalten entspricht der Workspace-Uebersicht.
- [ ] Overlay liegt ueber Sidebar und Hauptinhalt, nicht innerhalb eines
  transformierten Sidebar-Containers.
- [ ] Erfolgshinweis bleibt nach dem Schliessen beziehungsweise der Navigation
  sichtbar.
- [ ] Die Sprachnotiz landet im Notizbuch, das die Seitenleiste selbst gewaehlt
  hat (getrennter `activeCollection`-Zustand von der Uebersicht).

### 8.3 Diktat in eine offene Notiz

- [ ] Bei einer neuen Diktatvorlage erscheint zuerst die Auswahl.
- [ ] Bei bereits gebundener Vorlage startet die Aufnahme direkt im Overlay.
- [ ] Der Text wird an der gemerkten Editorposition eingesetzt.
- [ ] Nach der Transkription erscheint der Speicherzustand.
- [ ] Das Popup bleibt waehrend `saving` sichtbar.
- [ ] Das Popup schliesst erst bei `status === 'saved'` und leerer Outbox.
- [ ] Ein gueltiger Titelvorschlag wird vor dem Schliessen gespeichert.
- [ ] Ein selbst vergebener Titel wird nicht veraendert.

### 8.4 Fehlerfaelle

- [ ] Abgelehnte Mikrofonberechtigung wird im Popup angezeigt.
- [ ] Fehlendes Mikrofon wird im Popup angezeigt.
- [ ] Zu kurze Aufnahme wird im Popup angezeigt.
- [ ] Provider-/Transkriptionsfehler wird im Popup angezeigt.
- [ ] Fehler beim Speichern der Notiz schliesst das Popup nicht automatisch.
- [ ] Offline-, Konflikt- und Validierungszustand werden nicht als erfolgreicher
  Save behandelt.
- [ ] Nach ausdruecklichem Schliessen bleibt der vorhandene lokale
  Offline-/Konfliktzustand der Notiz erhalten.

### 8.5 Bedienung und Barrierefreiheit

- [ ] Tab und Shift+Tab bleiben innerhalb des offenen Dialogs.
- [ ] Dialog und Status haben verstaendliche Accessible Names.
- [ ] Escape schliesst die Vorlagenauswahl oder einen Fehlerdialog.
- [ ] Escape schliesst nicht waehrend Aufnahme, Pause, Transkription oder Save.
- [ ] Backdrop-Klick folgt denselben Regeln.
- [ ] `Verwerfen` ist vor Verarbeitung erreichbar und eindeutig beschriftet.
- [ ] Darstellung funktioniert auf Desktop und Mobil.
- [ ] `prefers-reduced-motion` deaktiviert weiterhin die Pulsanimation.

### 8.6 Regressionen

- [ ] Aufgaben-Diktat funktioniert weiterhin.
- [ ] Logbuch-Diktat funktioniert weiterhin.
- [ ] Automatisches Zeitlimit beendet eine aktive Aufnahme weiterhin.
- [ ] Seitenwechsel oder Komponentenabbau hinterlassen keinen aktiven
  Mikrofonstream.
- [ ] Verschluesselte oder schreibgeschuetzte Notizen bieten weiterhin kein
  unzulaessiges Diktat an.
- [ ] Vorlagenauswahl und bestehende Vorlagenbindung bleiben unveraendert.

## 9. Verifikation

Backend in der CI-Reihenfolge:

```bash
composer audit
composer cs
composer stan
composer test
```

Frontend:

```bash
npm test
npm audit --audit-level=high
npm run build
```

Die Browserpruefung sollte mindestens in einem Chromium-basierten Browser und in
Safari beziehungsweise einem Browser mit dessen `MediaRecorder`-Implementierung
erfolgen. Besonders zu pruefen ist `stop()` aus einem pausierten Recorder.

## 10. Risiken und Gegenmassnahmen

| Risiko | Gegenmassnahme |
|---|---|
| Pausenzeit wird weitergezaehlt | Segmentbasierte aktive Laufzeit automatisiert testen. |
| Doppelklick startet zwei Mikrofonanfragen | `starting` vor dem ersten `await` setzen. |
| Stream wird nach Abbruch oder Seitenwechsel nicht freigegeben | Controller ausserhalb Alpine behalten, Startgeneration invalidieren und Tracks in allen Endpfaden stoppen. |
| Popup verschwindet zwischen Vorlage und Aufnahme | Picker und Recorder in derselben teleportierten Huelle halten und Zustand atomar wechseln. |
| Escape oder Backdrop versteckt laufende Verarbeitung | Zentraler Close-Guard nach bestehendem Busy-Muster. |
| Offene Notiz schliesst nach Transkription, aber vor Save | `handleVoiceRecording()` erst nach `saveNow()`, Statuspruefung und leerer Outbox aufloesen. |
| `saveNow()` schluckt einen Fehler | Nach dem Await `status`, `saveError` und Outbox explizit pruefen. |
| Titel ist noch nicht gespeichert | `applyVoiceTitle()` und `savePageTitle()` abwarten. |
| Task-Seite rendert das Panel je Kategorie | Task-Panel nicht in das Notiz-Popup verschieben; bestehende Inline-Struktur belassen. |
| Erfolgshinweis haelt das Modal unnoetig offen | `voiceNotice` ausserhalb der modalen Huelle rendern. |
| Browser unterstuetzt Pause unvollstaendig | Native Methoden kontrolliert aufrufen, Fehler sichtbar machen und reale Browser pruefen. |
| Modell leitet ein anderes Notizbuch ab als gewaehlt | Expliziter `notebookId` hat im Service Vorrang vor der Model-Ableitung (`$notebookId ?? $result['notebook_id']`). |
| Notizbuch verschwindet waehrend der Aufnahme | `PageService::create()` antwortet wie bei manueller Anlage mit 422; die Aufnahme ist dann verbraucht - bewusst konsistent zum bestehenden Verhalten. |

## 11. Abnahmekriterien

- [ ] Beim Start einer Notizaufnahme erscheint das vorhandene Overlay mit
  `Aufnahme läuft`.
- [ ] Aufnahme kann pausiert und ohne Zeitverlust fortgesetzt werden.
- [ ] `Fertig` funktioniert aus aktivem und pausiertem Zustand.
- [ ] Nach `Fertig` startet genau einmal die vorhandene Transkriptionspipeline.
- [ ] Eine neue Sprachnotiz schliesst das Overlay erst nach erfolgreicher
  `201`-Antwort.
- [ ] Ein Diktat in eine offene Notiz schliesst das Overlay erst nach
  erfolgreichem Autosave und leerer Outbox.
- [ ] Eine aus einem geoeffneten Notizbuch gestartete Sprachnotiz wird in genau
  diesem Notizbuch angelegt; ohne Auswahl bleibt es bei der Ableitung aus dem
  Inhalt.
- [ ] Transkriptions- und Speicherfehler bleiben sichtbar und fuehren nicht zu
  einem falschen Erfolg.
- [ ] `Verwerfen` beendet Stream und Timer ohne Upload.
- [ ] Keine neuen Libraries, Endpunkte, Architekturbausteine oder
  Datenbankaenderungen wurden eingefuehrt; `/api/voice/notes` hat lediglich das
  optionale Formularfeld `notebook_id` erhalten.
- [ ] Aufgaben-, Logbuch-, Offline-, Verschluesselungs- und sonstige bestehende
  App-Fluesse zeigen keine Regression.
- [ ] Frontendtests, Backendpruefungen und Produktionsbuild laufen erfolgreich.

## 12. Empfohlene Umsetzungsreihenfolge

1. [ ] Fake-`MediaRecorder` und Grundgeruest fuer `tests/Frontend/voice.test.js`
   anlegen.
2. [ ] Aktive Zeitmessung sowie `pause()`/`resume()` im Low-Level-Controller
   implementieren und testen.
3. [ ] `voiceRecorderMixin()` um neue Zustaende und Aktionen erweitern und
   testen.
4. [ ] `voice_panel.php` um Aufnahme-, Pause-, Fortsetzen- und Speicheranzeige
   erweitern.
5. [ ] `voice_template_picker.php` als durchgehende modale Huelle verdrahten.
6. [ ] Doppelte Inline-Panels aus den drei Notizkontexten entfernen.
7. [ ] Autosave-Barriere und abwartbaren Titel in `notePage.js` implementieren.
8. [ ] Notizbuchbindung end-to-end umsetzen: `notebook_id` im Frontend mitsenden,
   Controller und Service erweitern, Backendtests nach Abschnitt 7.5 ergaenzen.
9. [ ] Icons und minimale Styles ergaenzen.
10. [ ] Automatisierte Frontendtests vollstaendig ausfuehren.
11. [ ] Manuelle Pruefmatrix fuer beide Notizwege und Fehlerfaelle durchgehen.
12. [ ] Vollstaendige Backend- und Frontend-Verifikation ausfuehren.
