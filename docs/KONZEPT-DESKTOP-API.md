# Konzept: Desktop-Assistant — KI-Proxy mit Paarung und Verbrauchsbuch

| Feld | Wert |
|---|---|
| Status | Umgesetzt (Ausbaustufe Desktop-Assistant), noch nicht ausgerollt |
| Datum | 2026-08-27 |
| Bezug | Vorgeschlagen: FR-DESKAPI-01–09; wiederverwendet: FR-NVOICE (Automations-Token), FR-VOICE-05 (KI-Einstellungen) |
| Client-Schnittstelle | `docs/API-DESKTOP-ASSISTANT.md` (eingefrorener Kontrakt) |

---

## 1. Ziel und Geltungsbereich

Der **Desktop-Assistant** ist eine bereits bestehende Desktop-Anwendung, die
künftig ihre KI-Aufrufe (Chat, Audio-Transkription) nicht mehr direkt an einen
KI-Anbieter schickt, sondern an das NasmutNotes-Backend. Der Server wird zum
**reinen Re-Routing**: Er nimmt Standard-OpenAI-Anfragen entgegen, rechnet sie
an den konfigurierten KI-Dienst weiter und liefert die Antworten unverändert
zurück.

Das bringt drei Dinge, die ein direkter Zugang zum KI-Anbieter nicht hat:

1. **Der API-Schlüssel verlässt den Server nie.** Die Desktop-App bekommt
   keinen Schlüssel in die Hand, der kopier- und leckbar ist.
2. **Nur echte NasmutNotes-Nutzer kommen durch.** Der Zugang hängt am
   Benutzerkonto, nicht an einem frei weitergebbaren Provider-Key.
3. **Jeder Aufruf ist zuordenbar und verrechenbar.** Ein Verbrauchsbuch hält
   je Aufruf fest, welcher Nutzer welches Modell mit wie vielen Tokens
   belastet hat — für Nutzer und Admin sichtbar, für den Admin kostbar
   ausgewiesen.

Der Server trifft dabei bewusst die wichtigsten Entscheidungen selbst:
Welches Modell der Assistant benutzt, entspricht der Administrator
(FR-DESKAPI-02), nicht dem Client — der Client kann das Feld sogar weglassen.
Die Transkription nutzt unverändert die Einstellungen der Sprachnotizen
(FR-DESKAPI-04). Was der Server nicht selbst entscheidet, wandert 1:1 durch:
alle übrigen OpenAI-Parameter und die Antworten (inklusive Fehler des
Anbieters mit Statuscode und Fehlerkörper).

**Geltungsbereich:** Zwei Endpunkte (`chat/completions`,
`audio/transcriptions`), ein Ping (`me`), die Paarung inklusive
Bestätigungsseite, das Verbrauchsbuch samt Auswertungen und die Admin-Konfig
auf `/admin/ai`. Nicht im Geltungsbereich: Die Desktop-App selbst, Datei-
Uploads für die Wissensbasis, Agent-Loops mit Serveraufrufen und
verschlüsselte Notizinhalte als Chat-Kontext.

## 2. Grundentscheidungen

### 2.1 Re-Routing statt Proxy mit Client-Schlüsseln

Die Alternative — der Server vermittelt nur einen zeitlich begrenzten
Provider-Schlüssel und der Client ruft den Anbieter direkt an — hätte den
Schlüssel weiterhin im Client gespeichert und die Verbrauchszuordnung zu
Nutzerkonten gelöst. Das Re-Routing kostet Laufzeit auf dem eigenen Server
(jede Antwort läuft einmal durch PHP), die akzeptabel ist, weil Chat-Antworten
im Desktop-Kontext klein bleiben und Streaming den first byte früh liefert.

### 2.2 Strikt `OPENAI_KEY` — kein Admin-Dashboard-Schlüssel

Die Sprachnotizen haben eine bewusste Regel: Der Schlüssel liegt ausschließlich
in der Umgebung (`.env`), nicht in der Datenbank (FR-VOICE-05). Gründe: Ein
Geheimnis gehört zum Deployment; die Datenbank wandert in Sicherungen, der
Admin-Weboberfläche traut man ein Eingabefeld für Produktivschlüssel nicht zu.
Der Assistant erbt diese Regel vollständig — der Chat-Zieldienst muss also
denselben Schlüssel akzeptieren. Dafür kann der Admin den Ziel-Endpoint frei
wählen (eigene Infrastruktur mit gleichem Key), aber nicht einen zweiten
Schlüssel hinterlegen. Wer einen abweichenden Anbieter mit eigenem Key braucht,
bekommt hier absichtlich keine Tür: Siehe Abschnitt 12.

### 2.3 Paarung nach dem WhatsApp-/Nextcloud-Muster statt Client-Token

Die ersten Überlegungen sahen vor, der Client erzeugt selbst einen Token und
übergibt ihn per Browser-URL an eine Bestätigungsseite. Das ist der
schwächere Weg und wurde verworfen:

- Der Roh-Token würde durch **Browser-Historie, Server-Access-Logs und
  Screenshots der URL** laufen. Ein Token, der in der Adresszeile stand, gilt
  als kompromittiert.
- Ein öffentlicher Endpoint, an den beliebige Client-Tokens vorab
  registriert werden, lädt zum **Squatting** ein (fremde Tokens unter fremden
  Konten verankern oder nutzergebundene Einträge spammen).

Stattdessen der umgekehrte Weg, wie ihn WhatsApp Web und die
Nextcloud-Desktop-App zeigen und wie er in RFC 8628 (Device Authorization
Grant) standardisiert ist — nur ohne OAuth-Ballast:

1. Der Client startet eine Paarungssitzung und bekommt einen **kurzen
   Anzeige-Code** (`user_code`) und einen **geheimen Zufallscode**
   (`device_code`).
2. Der Browser öffnet die Bestätigungsseite nur mit dem Anzeige-Code — in der
   URL steht kein Geheimnis, das es zu schützen gälte.
3. Der angemeldete Nutzer bestätigt; der Server vermerkt nur, *dass*
   bestätigt wurde.
4. Der Client holt mit dem geheimen `device_code` ab — und erst **bei der
   Abholung erzeugt der Server den Token**. Der Roh-Token existiert nirgends
   gespeichert, läuft nie durch eine URL und wird genau einmal ausgeliefert
   (FR-DESKAPI-01).

### 2.4 Eingehängt in die bestehende Token-Infrastruktur

Der bei der Paarung entstehende Token ist **ein gewöhnlicher
Automations-Token** (`device_tokens`), erweitert um Herkunft und Identität:
`source` (`manual` für NotesVoice-Kurzbefehle, `desktop` für gepaarte
Clients), `client_id` (stabile, vom Client generierte UUID) und `platform`.
Die vorhandene Auflösung (`DeviceTokenAuthMiddleware`, SHA-256-Hash,
`last_used_at`), die Widerrufslogik und die Schnittstelle in den
Profileinstellungen gelten unverändert — es gibt genau eine Token-Bauart
auf dem Server. Der Unterschied liegt allein in der *Entstehung* (manuell
kopiert vs. gepaart) und in den Routen, die Token akzeptieren.

## 3. Nutzerfluss

**Erstverbindung:** Der Client merkt beim Ping (`GET /api/assistant/me`),
dass sein Token fehlt oder abgewiesen wurde (401). Er startet die Paarung,
öffnet den Browser unter
`https://<server>/assistant/pair?code=K7M2-Q4XP` und zeigt selbst den Code
an, falls der Browser nicht aufgeht. Der Nutzer — im Browser angemeldet —
sieht Name und Plattform des Geräts und tippt „Verbinden“. War er nicht
angemeldet, meldet er sich an und öffnet den Link erneut; der Code bleibt
zehn Minuten gültig. Alternativ trägt er den Code in der Web-App unter
*Einstellungen → Verbundene Clients* von Hand ein (FR-DESKAPI-01).

**Alltag:** Der Client prüft mit dem Ping, ob die Verbindung steht und der
Assistant freigeschaltet ist (`assistant.usable`). Danach laufen Chat und
Transkription wie gegen `api.openai.com` — gleiche Endpunkte, gleiche
Parameter, gleiche Fehlerformen. Trennt der Nutzer das Gerät in den
Einstellungen, bekommt der Client beim nächsten Ping 401 und startet die
Paarung neu.

**Verbrauch:** Nutzer und Admin sehen Token- und Kostensummen ohne Zutun
(FR-DESKAPI-05/06/08) — Abschnitt 7.

## 4. Paarung: Zustände, Datenmodell, Rotation

### 4.1 Zustandsautomat

```
                start()                    approve()                poll()
  ┌─────────┐ ──────────► ┌─────────┐ ──────────► ┌──────────┐ ────────► ┌──────────┐
  │ (keiner)│             │ pending │             │ approved │           │ consumed │
  └─────────┘             └─────────┘             └──────────┘           └──────────┘
       ▲                        │                        │                     │
       └────────────────────────┴────────────────────────┴─────────────────────┘
              expires_at verstrichen, neuer start() desselben client_id
              oder verbrauchter Code → Zeile gelöscht / „expired“
```

- `pending` → `approved`: nur durch den angemeldeten Nutzer (Session + CSRF).
  Ein Code kann **nur einmal** bestätigt werden; die Bestätigung steht fest,
  auch wenn der Client den Code niemals abholt.
- `approved` → `consumed`: genau ein Abholvorgang erzeugt den Token und
  markiert die Sitzung verbraucht. Ein zweites Poll auf denselben
  `device_code` antwortet `expired` — der Client startet neu.
- Jeder Zustand endet spätestens bei `expires_at` (10 Minuten). Verfallene
  Zeilen werden beim nächsten `start()` lazy gelöscht, es gibt keinen Cron.

### 4.2 Codes

- `user_code`: 8 Zeichen aus einem 31er-Alphabet **ohne I, O, 0, 1**
  (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`), dargestellt als `XXXX-XXXX`. Raum
  31⁸ ≈ 8,5 × 10¹¹ — Bruteforce scheitert an der Lebensdauer von 10 Minuten
  und den Rate-Limits, bevor auch nur ein Bruchteil des Raums abgefragt ist.
  Serverseitig liegt nur der SHA-256-Hash der **normalisierten Form** (ohne
  Bindestrich, groß), damit `ABCD-EFGH` und `ABCDEFGH` dieselbe Sitzung
  finden.
- `device_code`: 32 Zufallsbytes (`bin2hex`, 64 Zeichen), ebenfalls nur als
  Hash gespeichert. Er schützt die Abholung und wird nie in einer URL
  geführt.
- Der erzeugte Verbindungstoken ist `bin2hex(random_bytes(32))` — gleiches
  Format wie Session- und Automations-Token.

### 4.3 Datenmodell

Migration `0037_extend_device_tokens_for_pairing.sql`:

```sql
ALTER TABLE device_tokens ADD COLUMN source TEXT NOT NULL DEFAULT 'manual';
ALTER TABLE device_tokens ADD COLUMN client_id TEXT;
ALTER TABLE device_tokens ADD COLUMN platform TEXT;

-- Nur aktive Token sind eindeutig: Ein getrennter Client behält seine
-- client_id in der Historie, ein neues Pairing derselben ID darf aber
-- einen frischen Token anlegen.
CREATE UNIQUE INDEX idx_device_tokens_client
    ON device_tokens(client_id) WHERE client_id IS NOT NULL AND revoked_at IS NULL;
```

Migration `0038_create_device_pair_requests.sql`:

```sql
CREATE TABLE device_pair_requests (
    id INTEGER PRIMARY KEY,
    user_code_hash TEXT NOT NULL UNIQUE,
    device_code_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL,
    label TEXT NOT NULL,
    platform TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    approved_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    approved_at TEXT,
    consumed_at TEXT,
    token_id INTEGER
);
```

Die Zeile hält **nichts Verwertbares**: Codes gehasht, der Roh-Token wird erst
im Abholaugenblick erzeugt und nie geschrieben (`token_id` verweist nur fürs
Audit-Log auf das Ergebnis). Bestätigung und Abholung landen als
`device_pair_started/approved/completed` im Audit-Log.

### 4.4 Rotation und Grenzen

Eine erneute Paarung derselben `client_id` **widerruft den bisherigen Token
und ersetzt ihn** — neu paaren heißt neu verbinden, nicht vervielfachen
(FR-DESKAPI-09). Damit bleibt die Geräteliste sauber und das Limit von 20
verbundenen Geräten je Nutzer (übernommen aus der Automations-Token-Logik)
greift weiter. Ein verlorener Token braucht also keinen Admin: Client neu
paaren, fertig.

## 5. Chat-Routing

### 5.1 Was der Server überschreibt, was er durchreicht

Aus der Client-Anfrage wird **nur** `model` ersetzt — gegen
`assistant_chat_model`, leer konfiguriert gegen das gemeinsame Default-LLM
der KI-Funktionen (Abschnitt 8). Alles andere wandert unverändert weiter:
`messages`, `temperature`, `tools`, `response_format`, `max_tokens` und
jeder Parameter, den der Anbieter künftig einführt. Der Client kann `model`
weglassen; sendet er eines, wird es stillschweigend ignoriert — der Admin
entscheidet (FR-DESKAPI-02).

Größe und Form der Anfrage werden grob begrenzt (2 MB Körper, JSON-Objekt
erforderlich); alles Feinere prüft der Anbieter, dessen Fehler (4xx/5xx mit
OpenAI-Error-Körper) mit Statuscode und Content-Type **unverändert**
durchgereicht werden. Nur Connectivity-Probleme und unlesbare Antworten
formt der Server in eine OpenAI-förmige 502-Antwort um, damit SDKs sie ohne
Sonderfall verstehen.

### 5.2 Streaming: Passthrough mit Mitleser

Bei `stream: true` ist die Antwort ein **SSE-Durchlauf**: Guzzle wird mit
`stream => true` gestellt, der Antwortstrom als PSR-7-Stream in die
Slim-Antwort gehängt. Slims ResponseEmitter liest bei unbekannter Länge
chunkweise (4 KB) bis EOF — genau dafür ist der Stream gebaut: keine
Content-Length, nicht seekbar, keine Zwischenspeicherung der ganzen Antwort.

Damit die Anfrage nicht die Session blockiert (hier irrelevant: Sessions
liegen in der Datenbank, es gibt kein PHP-Session-Locking) und Proxies
nicht puffern, setzt der Server `Cache-Control: no-store` und
`X-Accel-Buffering: no`. Unter Apache mit `mod_deflate` bleibt der Endpunkt
komprimierungsfrei zu konfigurieren; PHP-FPM zählt nur CPU-Zeit, lange
Streams sind unkritisch.

**Das Verbrauchsbuch muss trotzdem fündig werden.** OpenAI liefert beim
Streaming die Tokenzahlen nur im End-Chunk — und nur, wenn der Aufruf
`stream_options: {"include_usage": true}` mitbringt. Der Server ergänzt
diese Option stillschweigend (OpenAI-kompatible Dienste ignorieren
unbekannte Felder, der Extra-Chunk mit `choices: []` ist SDK-tolerierbar)
und liest sie mit einem **Tee-Stream** mit, der die Chunks an den Client
weiterreicht, während er nebenbei sammelt:

- die letzten 64 KB des Rohstroms (dort steht der End-Chunk) und
- bis zu 1 MB des verketteten `delta.content` (für die Schätzung).

Nach EOF wertet der Mitleser aus: gefundenes `usage` hat Vorrang, sonst wird
aus den Textlängen geschätzt und der Eintrag als geschätzt markiert. Der
Mitlsepuffer ist gekappt: Kappt der Client die Verbindung vor EOF, endet die
Lese-Schleife im Emitter und es wird nichts gebucht — verlorene
Abbruch-Tokens sind der akzeptierte Preis, keine Teil-Schätzwerte in
abgebrochenen Antworten.

### 5.3 Limits

| Grenze | Wert | Ebene |
|---|---|---|
| Chat-Anfragen je Nutzer | 60 / 5 Min | Rate-Limiter (`assistant-chat:{uid}`) |
| Transkriptionen je Nutzer | 20 / 5 Min | Rate-Limiter (wie Sprachnotizen) |
| Chat-Körpergröße | 2 MB | Controller (zusätzlich zu PHP-Post-Limits) |
| Aufnahmegröße | `voice_max_mb` (Vorgabe 25 MB) | Assistant-Service |

## 6. Transkription-Endpoint

`POST /api/assistant/audio/transcriptions` ist die OpenAI-Form der
bestehenden Sprachpipeline — aber **ohne Produktentscheidung**: es entsteht
keine Notiz, kein Notizbuch-Vorschlag, keine Nachbearbeitung. Der Server
nutzt unverändert die Transkriptionseinstellungen der Sprachnotizen
(Modell, Sprache, Base-URL, Größenlimit), erzwingt `response_format=json`
und ignoriert `model`/`language` aus der Client-Anfrage
(FR-DESKAPI-04). Die Antwort (inklusive `usage` bei
`gpt-4o-transcribe`-Familie) wandert 1:1 zurück; für `whisper`-Modelle ohne
Usage greift die Schätzung aus Abschnitt 7.

Die Aufnahme landet für den Aufruf in `VOICE_TMP_PATH` (gleiche Ablage wie
die Sprachnotizen, Dateiname entscheidet über das Format) und wird sofort
danach gelöscht.

## 7. Verbrauchsbuch und Kosten

### 7.1 Eine Engstelle, ein Buch

Jeder KI-Aufruf des Servers läuft durch `OpenAiClient` — Sprachnotizen
(Transkription, Nachbearbeitung, Quick, Log- und Task-Diktat), die
Notiz-KI-Überarbeitung und künftig der Assistant. Genau dort wird gebucht:
`OpenAiClient` bekommt einen optionalen `AiUsageRecorder` und die Aufrufer
reichen einen **`AiCallContext`** (Nutzer-ID, Feature) nach. Features:
`voice_note`, `voice_quick`, `voice_log`, `voice_tasks`, `note_ai`,
`desktop_chat`, `desktop_transcribe` (FR-DESKAPI-05). Ohne Kontext verhält
sich der Client wie bisher — kein Aufruf wird gezwungen, gebucht zu werden,
und bestehende Tests bleiben unverändert gültig.

Der Assistant geht seinen eigenen HTTP-Pfad (Passthrough braucht andere
Antwortsemantik als die JSON-Wrapper des Sprachdiensts), bucht aber über
denselben Recorder.

### 7.2 Was gespeichert wird — und was nicht

Migration `0039_create_ai_usage_log.sql`:

```sql
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
```

**Kein Nachrichteninhalt, kein Prompt, kein Dateiname.** Das Buch dient der
Verrechnung, nicht der Beobachtung; Notizinhalte sind teils verschlüsselt
und dürfen es im Log nicht indirekt wieder werden. Die Usage-Angabe des
Anbieters hat Vorrang (`prompt_tokens`/`completion_tokens` beim Chat,
alternativ `input_tokens`/`output_tokens` bei Transkription); fehlt sie,
wird grob aus der Zeichenlänge geschätzt (≈ 4 Zeichen je Token) und
`estimated = 1` gesetzt. Modelle, die gar kein Usage liefern (`whisper-1`),
landen mit 0 Tokens und geschätzt im Buch — dokumentierte Lücke, der
Vorgabe-Modellpfad (`gpt-4o-mini-transcribe`) liefert Usage.

### 7.3 „Letzte 30 Tage“ und Kosten zur Anzeigezeit

- „Letzter Monat" ist ein **rollierendes 30-Tage-Fenster** gegen die
  UTC-Zeitstempel der Einträge — kein Kalendermonat, keine Zeitzonen-Diskussion
  (FR-DESKAPI-06).
- Migration `0040_create_ai_model_costs.sql` hält den Katalog: Modell,
  Input-/Output-Preis in **Euro je 1 Mio. Tokens**, Währung. Der Admin kann
  **jedes** Modell pflegen — auch die bereits laufenden der Sprachnotizen und
  der Notiz-KI (FR-DESKAPI-07).
- Die Verrechnung geschieht **zur Anzeigezeit** als Join über den Katalog.
  Modelle ohne Eintrag zählen mit, kosten aber nichts (`cost` bleibt `null`,
  `priced` markiert die Situation). Korrigiert der Admin einen Preis, wirkt
  das rückwirkend auf die Historie — gewollt, denn Kosten sind eine
  Betrachtung, kein Ereignis.
- gemischte Währungen führen zu Unsinn in Summen; der Katalog erlaubt sie,
  erwartet aber einheitliche Pflege (eine Währung pro Einsatz).

## 8. Admin-Konfiguration

Neue Einstellungen auf `/admin/ai`, Lagerung wie gehabt in `app_settings`
mit `.env`-Anfangswerten:

| Schlüssel | Bedeutung | Fallback-Kette |
|---|---|---|
| `assistant_enabled` | Freischaltung | offen → `OPENAI_KEY` vorhanden? |
| `assistant_chat_model` | LLM für den Desktop-Assistant | offen → `voice_postprocess_model` → `VOICE_POSTPROCESS_MODEL` (env) |
| `assistant_chat_base_url` | Ziel-Endpoint des Chat-Routing | offen → `voice_openai_base_url` → `OPENAI_BASE_URL` (env) |

Die Staffel ist der Kern: **Solange der Admin nichts Eigenes setzt, gilt der
gemeinsame Default der KI-Funktionen** — der Assistant erbt automatisch
jede spätere Änderung an den Sprachnotizen-Einstellungen. Das API-Key-Feld
wird wie gewohnt nie ausgeliefert, nur die letzten vier Zeichen zur
Wiedererkennung.

## 9. Oberflächen

- **Nutzer, Einstellungen → Verbundene Clients** (neuer, immer sichtbarer
  Bereich): eigene KI-Verbrauchskacheln (30 Tage / gesamt, Tokens + Kosten),
  manuelle Code-Eingabe für die Paarung, Geräteliste mit Herkunfts-Badge
  („Desktop“ / „Automation“), Plattform und letzter Nutzung, Trennen bzw.
  Widerrufen. Die bisherige Token-Liste im Speech2Text-Bereich ist hier
  aufgegangen; das manuelle Anlegen eines Automations-Tokens bleibt
  NotesVoice-Vorbehalt.
- **Admin, `/admin/ai`:** drei neue Sektionen — Desktop-Assistant
  (Freischaltung, Modell, Ziel-Endpoint), Modellkosten (Tabelle mit
  Pflegen/Entfernen, Formular mit Komma-Toleranz), Verbrauch & Kosten
  (je Nutzer Tokens und Kosten beider Fenster, Gesamtzeile). Alles
  Alpine-CSP-konform ohne Inline-Ausdrücke; die Bestätigungsseite der
  Paarung ist eine eigenständige View mit eigener Komponente
  (`resources/js/assistant/pair.js`).

## 10. Betroffene Komponenten

| Bereich | Dateien |
|---|---|
| Migrationen | `0037`–`0040` (siehe oben) |
| Paarung | `app/Domain/Auth/DevicePairingService.php`, `app/Repositories/DevicePairRequestRepository.php`, Erweiterung `DeviceTokenService`/`DeviceTokenRepository` |
| Proxy | `app/Domain/Assistant/` (`AssistantService`, `AssistantSettings`, `UsageTeeStream`, `UpstreamReply`, `AssistantServiceException`), `app/Controllers/AssistantController.php` |
| Verbrauch | `app/Domain/Ai/` (`AiUsageRecorder`, `AiCallContext`, `AiUsageService`), `app/Repositories/AiUsageRepository.php`, `AiModelCostRepository.php`, Engstelle in `OpenAiClient` |
| Bestandsanpassung | `VoiceNoteService` und `NoteRewriteService` (Kontexte), `CsrfMiddleware` (anonyme POSTs), Routes, Container |
| Oberflächen | `notebook_nav.php` (Einstellungen), `admin/ai.php` + `adminDashboard.js`, `views/assistant/pair.php` + `resources/js/assistant/pair.js` |

## 11. Sicherheit — Zusammenfassung

**Schutzwirkung:**

- Der Provider-Schlüssel bleibt im Server-Deployment; keine
  Admin-Oberfläche, keine Datenbank, kein Client enthält ihn.
- Zugang nur für aktive NasmutNotes-Nutzer, geräteweise sichtbar und
  jederzeit trennbar; getrennte Token scheitern sofort (401).
- Paarung ohne Geheimnis in URLs: Anzeige-Code ist kurzlebig und wertlos
  ohne Bestätigung durch den angemeldeten Nutzer; `device_code` und
  Verbindungstoken laufen nur über HTTPS-Körper, Token nur einmal.
- Rate-Limits auf jeder offenen und jeder teuren Route (Abschnitt 5.3),
  zusätzlich zu den globalen Middleware-Schichten.
- Verbrauchsbuch ohne Inhalte: Zuordnung ist möglich, Mitlesen von Notiz-
  oder Chat-Inhalten über das Log nicht.

**Kein Schutz:**

- Ein abgefischter Verbindungstoken gilt bis zum Trennen durch den Nutzer —
  daher die Sichtbarkeit mit `last_used_at` in den Einstellungen.
- Der Server sieht alle Chat-Inhalte im Klartext (er muss sie weiterleiten).
  Ende-zu-Ende-Vertraulichkeit gegenüber dem eigenen Server ist kein Ziel —
  anders als bei verschlüsselten Notizen, die bewusst nie als Chat-Kontext
  verwendet werden.
- Die Kostenverrechnung ist vertrauensvoll: Sie basiert auf den Usage-Angaben
  des Anbieters bzw. einer groben Schätzung, nicht auf Messung im eigenen
  Haus.

**CSRF-Feinheit:** Die Paarungs-Endpunkte sind die ersten **anonymen
POST-Routen** der Anwendung. Das globale Double-Submit-CSRF schützt
cookie-authentifizierte Anfragen — ohne Session-Cookie gibt es keinen
ambienten Kontext, auf den sich ein Angriff rechnen könnte. Die Ausnahme im
`CsrfMiddleware` ist entsprechend formuliert: ohne Session-Cookie generell
durchgelassen (danach greifen Rate-Limits und die Nutzerautorisierung),
mit Session-Cookie weiterhin streng geprüft — die Bestätigung im Browser
bleibt also voll geschützt.

## 12. Bewusste Auslassungen und offene Punkte

- **Kein eigener API-Key für abweichende Anbieter.** Der Chat-Zieldienst
  muss den `OPENAI_KEY` akzeptieren. Ein zweiter, im Dashboard pflegbarer
  Schlüssel wäre der logische nächste Schritt, bricht aber die Regel
  „Geheimnisse gehören ins Deployment" (siehe 2.2) — Entscheidung vertagt.
- **Keine Ausgabenbudgets/Obergrenzen.** Der Verbrauch ist sichtbar, aber
  nicht begrenzbar; ein monatliches Token-Budget je Nutzer wäre eine
  natürliche Erweiterung des Verbrauchsbuchs.
- **Keine Auswertung je Feature in der Oberfläche.** Das Buch führt
  `feature` mit, angezeigt wird je Modell; wer den Assistant vom Diktat
  trennen will, kann das aus den Daten ableiten.
- **Verlorene Abbruch-Tokens.** Bricht ein Client einen Stream ab, wird
  nicht gebucht (siehe 5.2). Unter-Reporting, kein Over-Reporting.
- **Client-seitige `model`-Auswahl** bleibt grundsätzlich ausgeschlossen;
  ein „Erlaubnisliste mehrerer Modelle“ wäre denkbar, ist aber nicht
  konfiguriert.

## 13. Teststrategie

Gegen In-Memory-SQLite (bestehendes Muster), CI-Reihenfolge wie gehabt:

- **Pairing (Integration):** Start → Poll `pending` → Approve → Poll liefert
  Token genau einmal; verbrauchter und abgelaufener Code antwortet
  `expired`; Re-Pairing derselben `client_id` rotiert (alter Token tot,
  genau ein aktiver); fremde/verbrauchte Codes lassen sich nicht erneut
  bestätigen; `describeByUserCode` zeigt Label und Plattform, nie Geheimnisse.
- **Proxy (Integration, injizierter Guzzle-Mock):** Modell-Override bei
  weitergereichten Parametern; Usage-Buchung aus der Provider-Antwort;
  SSE-Passthrough bei gleichzeitiger Buchung aus dem End-Chunk
  (`include_usage` in der Anfrage nachweisbar); Schätzung, wenn der Anbieter
  kein Usage liefert; Fehlerdurchreich ohne Buchung; Ablehnung bei
  ausgeschalteter Funktion; Transkription nutzt Sprachnotiz-Einstellungen und
  mappt `input_tokens`/`output_tokens`.
- **Verbrauch (Integration):** Provider-Usage, Schätzung samt
  `estimated`-Flag, `input_tokens`-Mapping, Kosten-Join, rollierendes
  30-Tage-Fenster (Zeile künstlich 31 Tage alt), Admin-Aggregation je Nutzer
  und Modell, Katalog-Validierung (Komma-Preise, Ablehnung Negativpreise).
- **Bestand:** Die Voice-/Note-AI-Tests laufen unverändert durch und belegen
  die Rückwärtskompatibilität der erweiterten Signaturen.

## 14. Abnahmekriterien

1. Ein unverbundener Client paart sich in unter einer Minute: Code →
   Browser-Bestätigung → Token; die Geräteliste zeigt das Gerät mit Badge
   und Plattform.
2. Trennen des Geräts wirkt sofort; der nächste Ping liefert 401 und der
   Client kann sich neu paaren, ohne dass ein alter Eintrag bleibt.
3. Ein OpenAI-SDK, das auf `https://<server>/api/assistant` gerichtet ist,
   kann Chat (streamend und nicht-streamend) und Transkription ohne eigene
   Sonderbehandlung nutzen; Fehler des Anbieters kommen im OpenAI-Format an.
4. Im Admin ist ein anderes Modell konfigurierbar; die nächste Chat-Anfrage
   nutzt es unabhängig davon, was der Client sendet.
5. Nach Chat- und Transkriptionsaufrufen erscheinen Tokens prompt in der
   Nutzer- und Admin-Auswertung; Kosten erscheinen, sobald der Admin das
   Modell im Katalog pflegt — auch rückwirkend.
6. Sprachnotizen und Notiz-KI buchen ihren Verbrauch ins selbe Buch, ohne
   dass sich ihr Verhalten ändert (Bestandstests grün).
7. Ohne `OPENAI_KEY` bzw. ausgeschaltet antworten die Proxy-Routen mit einer
   verständlichen 503 im OpenAI-Format; Paarung und Ping funktionieren
   unabhängig davon.
