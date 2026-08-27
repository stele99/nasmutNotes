# API: Desktop Assistant (Client-Schnittstelle)

Die Desktop-App spricht Standard-OpenAI: Sie richtet ihren OpenAI-Client auf
den NasmutNotes-Server und meldet sich mit einem Verbindungstoken an. Der
Server hält die Zügel — Modell und Ziel-Endpoint legt der Administrator fest,
der API-Schlüssel zum KI-Dienst bleibt auf dem Server, und jeder Aufruf wird
dem Nutzer im Verbrauchsbuch zugeordnet.

## 1. Einrichtung des Clients

### 1.1 Basis-URL und Authentifizierung

| Einstellung im OpenAI-Client | Wert |
|---|---|
| Base URL | `https://<server>/api/assistant` |
| API Key | Verbindungstoken aus der Paarung (siehe unten) |

Damit funktionieren die beiden unterstützten Endpunkte mit jedem
OpenAI-kompatiblen SDK bzw. HTTP-Client:

- `POST {base}/chat/completions` — Chat, inklusive Streaming
- `POST {base}/audio/transcriptions` — Audio-Transkription (multipart)

Alle Anfragen brauchen die Kopfzeile `Authorization: Bearer <Verbindungstoken>`.
Nur aktive NasmutNotes-Nutzer mit mindestens einem verbundenen Gerät kommen
durch; GET `{base}/me` eignet sich als Ping, um die Verbindung zu prüfen.

### 1.2 Paarung (Erstverbindung, WhatsApp-/Nextcloud-Stil)

Ablauf, sobald der gespeicherte Token nicht mehr funktioniert (Ping 401) oder
gar keiner vorhanden ist:

```
Client                          Server                          Browser des Nutzers
  |                               |                               |
  |-- POST /api/assistant/pair -->|                               |
  |   {label, client_id, platform}|                               |
  |<-- user_code, device_code ----|                               |
  |                               |                               |
  |-- Browser öffnet -------------|------------------------------>|
  |   https://<server>/assistant/pair?code=<user_code>            |
  |                               |<-- POST /api/assistant/pair/approve
  |                               |    {user_code}   (angemeldeter Nutzer)
  |                               |                               |
  |-- POST /api/assistant/pair/poll ----------------------------->|
  |   {device_code}               |                               |
  |<-- {status:"approved", token, user}                           |
  |   (Token speichern, nie wieder anzeigen)                      |
```

1. **`POST /api/assistant/pair`** (öffentlich, IP-begrenzt auf 10/5 Min):

   ```json
   {
     "label": "Steffens Arbeits-PC",
     "client_id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
     "platform": "Windows 11"
   }
   ```

   - `label` (Pflicht, 1–60 Zeichen): Anzeigename des Geräts.
   - `client_id` (Pflicht, 8–64 Zeichen aus `A-Za-z0-9._-`): **Stabile,
     vom Client selbst erzeugte ID**, lokal dauerhaft speichern. Ein erneutes
     Paarung mit derselben ID rotiert den Token des Geräts, statt ein zweites
     zu erzeugen.
   - `platform` (optional, 1–60 Zeichen): angezeigt bei der Bestätigung.

   Antwort (HTTP 201):

   ```json
   {
     "user_code": "K7M2-Q4XP",
     "device_code": "551e1c39d620e8892d31bbe5f0588588f47d0449f65766bdcaa4f4fc9c896bb7",
     "expires_in": 600
   }
   ```

   - `user_code`: kurz, für den Browser-Link (und die manuelle Eingabe).
   - `device_code`: **geheim, nur der Client kennt ihn**; damit wird gepollt.
   - Beide Codes verfallen nach 10 Minuten.

2. **Browser öffnen** unter `https://<server>/assistant/pair?code=<user_code>`.
   Der angemeldete Nutzer sieht Name und Plattform des Geräts und bestätigt
   mit „Verbinden". Ist der Browser noch nicht angemeldet: anmelden und den
   Link erneut öffnen (der Code bleibt 10 Minuten gültig). Alternativ kann der
   Nutzer den Code in der Web-App unter *Einstellungen → Verbundene Clients*
   von Hand bestätigen.

3. **`POST /api/assistant/pair/poll`** (öffentlich, IP-begrenzt auf 60/5 Min
   und je `device_code` auf 30/5 Min — empfohlenes Poll-Intervall: 5 Sekunden):

   ```json
   { "device_code": "<device_code aus Schritt 1>" }
   ```

   Antworten:

   | Status | Bedeutung | Handlung des Clients |
   |---|---|---|
   | `{"status":"pending","expires_in":n}` | Nutzer hat noch nicht bestätigt | weiter pollen |
   | `{"status":"approved","token":"…","user":{"name":"…","email":"…"}}` | verbunden | **Token sicher speichern, Pairing-Sitzung beenden** |
   | `{"status":"expired"}` | abgelaufen, verbraucht oder bereits abgeholt | neu paaren ab Schritt 1 |

   Der Token wird **genau einmal** ausgeliefert. Kommt die Antwort beim Client
   nicht an, startet die Paarung neu — der Nutzer bestätigt dann erneut.

4. **Ping/Prüfung**: `GET {base}/me` mit `Authorization: Bearer <token>`

   ```json
   { "user": { "name": "Steffen Epple", "email": "steffen@example.com" },
     "assistant": { "usable": true } }
   ```

   `assistant.usable === false` heißt: Der Administrator hat die Funktion
   ausgeschaltet oder es fehlt die Serverkonfiguration — dann bleiben Chat und
   Transkription mit Fehler 503 abgewiesen (nicht mit 401, das Token ist also
   in Ordnung).

### 1.3 Token-Lebenszyklus

- Der Token gilt **nur** für die `/api/assistant`-Routen — nicht für die
  Web-API des Notizbereichs.
- Er ist unter *Einstellungen → Verbundene Clients* sichtbar (Name, Plattform,
  letzte Nutzung) und jederzeit vom Nutzer trennbar. Danach antwortet der
  Server mit 401 → Paarung neu starten.
- Maximal 20 verbundene Geräte pro Nutzer (Automations-Token und
  Desktop-Clients zusammen).
- Der Server speichert nur einen SHA-256-Hash des Tokens; der Client muss ihn
  dauerhaft und lokal sicher ablegen.

## 2. Chat: `POST {base}/chat/completions`

Standard-OpenAI-Format, JSON-Körper, maximale Anfragegröße 2 MB:

```json
{
  "messages": [
    { "role": "system", "content": "Du bist ein präziser Assistent." },
    { "role": "user", "content": "Fasse diese Notiz zusammen: …" }
  ],
  "temperature": 0.7,
  "stream": true
}
```

- **`model` und `reasoning_effort` werden serverseitig gesetzt** und
  überschreiben, was der Client sendet: Der Administrator entscheidet,
  welches Modell und welcher Reasoning-Aufwand der Desktop-Assistent nutzt
  (das gemeinsame KI-Modell des Servers; ist kein Reasoning-Aufwand
  konfiguriert, wird der Parameter nicht mitgeschickt). Der Client kann
  beide Felder weglassen.
- Alle übrigen OpenAI-Parameter (`messages`, `temperature`, `tools`,
  `response_format`, `max_tokens`, …) werden unverändert an den KI-Dienst
  durchgereicht; die Antwort kommt unverändert zurück.
- `stream: true` → Server-Antwort ist **SSE** (`text/event-stream`), Chunk für
  Chunk durchgereicht, abgeschlossen mit `data: [DONE]`. Der Server ergänzt
  intern `stream_options.include_usage`, damit ein zusätzlicher End-Chunk mit
  `"choices": []` und dem `usage`-Objekt erscheint — OpenAI-SDKs ignorieren
  ihn, eigene Parser können ihn zur Verbrauchsanzeige nutzen.
- Ohne `stream` kommt das komplette OpenAI-Chat-JSON zurück (inkl. `usage`).

## 3. Transkription: `POST {base}/audio/transcriptions`

Multipart-Formular wie bei OpenAI; genutzt werden die Transkriptions-
einstellungen des Servers (Modell, Sprache, Adresse) — diejenigen der
Sprachnotizen, nicht die Chat-Konfiguration:

| Feld | Inhalt |
|---|---|
| `file` (Pflicht) | Audiodatei (`webm`, `mp4`, `m4a`, `mp3`, `ogg`, `wav`, `flac`); Obergrenze = `VOICE_MAX_MB` des Servers, Vorgabe 25 MB |
| `model` | wird ignoriert (Server setzt das Transkriptionsmodell) |
| `language` | wird ignoriert (Server setzt die konfigurierte Sprache, falls hinterlegt) |

Antwort: das OpenAI-Transkriptions-JSON, etwa
`{"text": "Guten Morgen …", "usage": {"input_tokens": 30, "output_tokens": 6, "total_tokens": 36}}`.

Rate-Limit: 20 Transkriptionen je Nutzer in 5 Minuten; Chat: 60 Anfragen in
5 Minuten.

## 4. Fehlerformat

Fehler, die **der Server selbst** erzeugt, folgen dem OpenAI-Error-Objekt:

```json
{ "error": { "message": "…", "type": "invalid_request_error" } }
```

| HTTP | `type` | Bedeutung |
|---|---|---|
| 400 | `invalid_request_error` | kaputter JSON-Körper / fehlende Datei / zu große Aufnahme |
| 401 | – | fehlender oder unbekannter Token → neu paaren |
| 413 | `invalid_request_error` | Chat-Anfrage über 2 MB |
| 429 | `rate_limit_error` | Rate-Limit erreicht |
| 502 | `api_error` | KI-Dienst nicht erreichbar |
| 503 | `server_error` | Funktion ausgeschaltet oder Serverkonfiguration unvollständig |

Fehler, die **der KI-Dienst** liefert (z. B. 401 bei falschem Server-Schlüssel,
400 bei ungültigen Parametern), werden mit Statuscode, Content-Type und Körper
**unverändert** durchgereicht — der Client behandelt sie wie Fehler von
api.openai.com direkt.

## 5. Verbrauchs-Transparenz

Jeder Chat- und Transkriptions-Aufruf wird dem angemeldeten Nutzer zugeordnet
(Tokenaufruf ohne Nachrichteninhalte — gespeichert werden nur Feature, Modell
und Tokenzahlen). Der Nutzer sieht die Summen in der Web-App unter
*Einstellungen → Verbundene Clients → KI-Verbrauch* (letzte 30 Tage und
gesamt); der Administrator sieht Kosten je Nutzer unter */admin/ai*, sobald er
Modelle im Kostenkatalog (Euro je 1 Mio. Tokens, Input/Output getrennt)
hinterlegt hat.
