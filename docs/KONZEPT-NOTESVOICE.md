# Konzept: NotesVoice — Diktat per Rückseitentipp

| Feld | Wert |
|---|---|
| Status | Konzeptentwurf, noch nicht implementiert |
| Datum | 2026-08-19 |
| Bezug | FR-VOICE-01–07 (bestehend, wird wiederverwendet), neu vorgeschlagen: FR-NVOICE-01–08 |

---

## 1. Ziel und Geltungsbereich

**NotesVoice** ist ein Schnellerfassungs-Pfad für Diktate, ausgelöst durch einen
Doppeltipp auf die Rückseite des iPhones. Der diktierte Text landet — nach
Transkription (Whisper über die bestehende OpenAI-Anbindung) und
Nachbearbeitung durch ein zweites Modell — als sauberer Fließtext in der
Zwischenablage. Es entsteht dabei **keine Notiz und kein sonstiger
Datenbankeintrag**; der Text ist rein transient und zum sofortigen Einfügen in
eine beliebige andere App gedacht (Nachricht, Mail, ein offenes Dokument, …).

Die ursprüngliche Idee war, per Automation eine URL der App zu öffnen, dort im
Browser automatisch aufzunehmen und die Seite danach wieder zu schließen.
Abschnitt 2 erklärt, warum das auf iOS nicht zuverlässig funktioniert, und
begründet die hier gewählte Architektur, die genau das gewünschte Endverhalten
("man ist wieder in der alten App") tatsächlich erreicht.

Wiederverwendet werden die bestehende Sprachpipeline (`VoiceNoteService`,
`OpenAiClient`, `VOICE_TMP_PATH`, alle Admin-Einstellungen aus FR-VOICE-05) und
ihre Grenzen (Formate, `VOICE_MAX_MB`, `VOICE_MAX_SECONDS`, Ratenbegrenzung,
`OPENAI_KEY` ausschließlich serverseitig).

## 2. Warum kein Browser-Fenster

Eine per **„URL öffnen"** gestartete Safari-/PWA-Seite kann sich auf iOS nicht
selbst schließen (`window.close()` wirkt nur auf Fenster, die per Skript
geöffnet wurden) und es gibt keine öffentliche API, mit der eine Webseite aktiv
zur zuvor aktiven App zurückspringt. Das ursprünglich gewünschte "schließt sich
die Webseite wieder" lässt sich mit diesem Ansatz bestenfalls als "Fertig — du
kannst jetzt zurückwechseln"-Hinweis annähern, nicht als automatischer Rücksprung.

Kurzbefehle-Automationen dagegen laufen, wenn sie **„Inhalte einer URL
abrufen"** statt **„URL öffnen"** verwenden, vollständig im Hintergrund der
Kurzbefehle-App bzw. als kurzes System-Overlay — die zuvor aktive App bleibt
die ganze Zeit im Vordergrund. Damit erfüllt dieser Weg die eigentliche
Anforderung exakt, statt sie nur anzunähern. Der Preis dafür: Es kann kein
Session-Cookie des eingeloggten Browsers mitgeschickt werden, die Automation
braucht also eine eigene, schwach berechtigte Authentifizierung (Abschnitt 4).

## 3. Nutzerfluss

1. Nutzer tippt doppelt auf die Rückseite des Geräts (iOS-Systemfunktion:
   Einstellungen → Bedienungshilfen → Berühren → Rückseitentipp →
   Doppeltippen → Kurzbefehl „NotesVoice").
2. iOS startet den Kurzbefehl „NotesVoice". Ein kleines Aufnahme-Overlay
   erscheint über der aktuellen App; der Nutzer spricht und beendet die
   Aufnahme mit einem Tipp auf „Fertig" im Overlay (kein automatisches
   Stille-Ende, siehe 6.3).
3. Der Kurzbefehl schickt die Aufnahme mit einem Automations-Token an
   `POST /api/voice/quick`.
4. Der Server transkribiert (Whisper-Modell) und bereitet den Text mit dem
   Postprocessing-Modell zu lesbarem Fließtext ohne Markdown-Syntax auf.
5. Der Kurzbefehl liest `text` aus der JSON-Antwort und kopiert ihn in die
   Zwischenablage.
6. Ein kurzer Ton/eine Haptik bestätigt den Abschluss. Die App, in der der
   Nutzer war, war nie im Hintergrund — er tippt jetzt einfach in ein
   Eingabefeld und fügt ein.

## 4. Authentifizierung: Automations-Token

Session-Cookies sind an den Browser gebunden und für eine
Kurzbefehle-Automation nicht erreichbar. Es entsteht ein neuer, bewusst
schwach berechtigter Token-Typ, getrennt vom Session-Mechanismus.

### 4.1 Eigenschaften

- Ein Nutzer kann sich beliebig viele benannte Automations-Token erzeugen
  (z. B. „iPhone von Steffen").
- Der Rohwert wird **einmalig** bei der Erzeugung angezeigt und nirgends
  gespeichert — gleiches Prinzip wie beim Session-Token (`bin2hex(32)`,
  serverseitig nur der SHA-256-Hash).
- Ein Token ist **ausschließlich** für `POST /api/voice/quick` gültig, für
  nichts anderes. Das begrenzt den Schaden, falls ein Kurzbefehl (der den
  Token im Klartext enthält) durch Diebstahl, iCloud-Kompromittierung oder
  versehentliches Teilen der `.shortcut`-Datei abfließt.
- Jederzeit widerrufbar in den Profileinstellungen; Widerruf wirkt sofort
  (nächste Anfrage bekommt 401).
- Kein automatisches Ablaufdatum in Version 1 — die Widerrufbarkeit reicht,
  ein Ablauf würde die Automation nach einiger Zeit lautlos kaputt machen.

### 4.2 Datenmodell

Neue Migration `database/migrations/0036_create_device_tokens.sql`:

```sql
CREATE TABLE device_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);

CREATE INDEX idx_device_tokens_user ON device_tokens(user_id);
```

`revoked_at` statt Löschen, damit ein Widerruf im Audit-Log nachvollziehbar
bleibt (gleiches Muster wie Einladungen/Sessions).

### 4.3 Komponenten

- `App\Repositories\DeviceTokenRepository` — CRUD, `findActiveByHash()`,
  `touchLastUsed()`.
- `App\Domain\Auth\DeviceTokenService` — `issue(User, string $label): string`
  (liefert den Rohwert), `listFor(User)`, `revoke(User, int $id)`. Struktur
  angelehnt an `SessionService` (Hashing) und `InviteService`
  (Nutzer-Eigentümerschaft, Widerruf).
- `App\Middleware\DeviceTokenAuthMiddleware` — liest
  `Authorization: Bearer <token>`, hasht, sucht aktiven, nicht widerrufenen
  Token, setzt bei Treffer das `user`-Attribut (identisch zu dem, das
  `SessionAuthMiddleware` setzt) und aktualisiert `last_used_at`. Wird **nur**
  an der einen Route registriert, nicht global — anders als
  `SessionAuthMiddleware`, das für alle Requests läuft.
- `App\Controllers\DeviceTokenController` — `index`/`store`/`destroy` unter
  `/api/profile/device-tokens`, strukturell identisch zu
  `UserInviteController` (eigene Ressourcen des angemeldeten Nutzers, gleiche
  Rate-Limit-Bremse beim Erzeugen, z. B. 10/Stunde).

### 4.4 CSRF-Ausnahme

`CsrfMiddleware` (public/index.php:58) läuft global und verlangt für jede
nicht-sichere Methode einen zum Cookie passenden `X-CSRF-Token`-Header. Das
Double-Submit-Verfahren schützt **cookie-authentifizierte** Anfragen vor
fremden Origins; ein Bearer-Token im `Authorization`-Header ist davon nicht
betroffen, weil eine fremde Seite ihn nicht "ambient" mitschicken kann, wie
sie es mit einem Cookie könnte.

Vorschlag: `CsrfMiddleware` überspringt die Prüfung, wenn ein
`Authorization: Bearer`-Header vorhanden ist **und** kein
`notes_session`-Cookie mitgeschickt wurde. Das ist eine allgemeine, korrekt
begründete Regel (nicht nur ein Sonderfall für diese eine Route) und bleibt
trotzdem eng, weil `DeviceTokenAuthMiddleware` ohnehin nur an
`/api/voice/quick` hängt — ein Bearer-Token an einer anderen Route bewirkt
schlicht nichts.

## 5. Serverseitige Verarbeitung

### 5.1 Endpunkt

```
POST /api/voice/quick
Authorization: Bearer <Automations-Token>
Content-Type: multipart/form-data
  audio: <Aufnahme>

→ 200 { "text": "…", "characters": 123 }
```

Route in `app/Config/routes.php`, eigene Gruppe (kein `RequireAuthMiddleware`
über die Session nötig — `DeviceTokenAuthMiddleware` liefert `user`, danach
greift dieselbe `RequireAuthMiddleware(true)`-Prüfung wie überall sonst, sie
kennt nur "ist `user` gesetzt", nicht wie):

```php
$app->post('/api/voice/quick', [VoiceNoteController::class, 'quick'])
    ->add(new DeviceTokenAuthMiddleware(...))
    ->add(new RequireAuthMiddleware(true));
```

Zusätzlich bleibt eine normale, eingeloggte Browser-Session als Auth-Weg
gültig (die global laufende `SessionAuthMiddleware` setzt `user` ggf. schon
vorher) — praktisch zum manuellen Testen ohne Token, in der Praxis nutzt
Kurzbefehle aber immer den Bearer-Token.

Neue Methode `VoiceNoteController::quick()`, strukturell wie `transcribe()`
(gleiches `guard()`, gleiches `assertWithinLimit()` — eigener Rate-Limit-Key
`voice-quick:{userId}`, gleiche Größenordnung wie die bestehenden 20/5 Min.).

### 5.2 `VoiceNoteService::transcribeQuick()`

Neue, schlanke Methode neben `transcribe()`:

```php
public function transcribeQuick(User $user, UploadedFileInterface $file): array
{
    $settings = $this->requireUsableSettings();
    $upload = $this->storeUpload($file, $settings);

    try {
        $transcript = $this->client->transcribe($settings, $upload['path'], $upload['name']);
    } finally {
        @unlink($upload['path']);
    }

    if ($transcript === '') {
        throw new ValidationException('In der Aufnahme wurde keine Sprache erkannt.');
    }

    $text = $this->refineQuick($settings, $transcript);

    return ['text' => $text, 'transcript' => $transcript];
}
```

Unterschiede zu `transcribe()`: **kein** Notizbuch-Matching, **keine**
Überschrift, **kein** ProseMirror-Dokument — dafür ist hier nichts nötig, weil
nichts gespeichert wird. `refineQuick()` nutzt denselben
`OpenAiClient::completeJson()`-Mechanismus mit einem eigenen, knapperen
Prompt, der ausdrücklich **Fließtext ohne Markdown-Syntax** verlangt (das
Ziel ist eine beliebige Zwischenablage, keine Notiz — Sternchen und
Bindestriche vor jeder Zeile wären beim Einfügen in eine Nachricht störend):

```php
public const DEFAULT_QUICK_PROMPT = <<<'PROMPT'
    Du bereitest ein diktiertes Sprachtranskript für die Zwischenablage auf.

    Aufgaben:
    1. Bereinige Erkennungsfehler, Versprecher und Füllwörter, setze
       Satzzeichen und Absätze. Der Inhalt bleibt unverändert - nichts
       ergänzen, nichts weglassen, nicht zusammenfassen.
    2. Schreibe als normalen Fließtext ohne Markdown-Syntax (keine "-", "#",
       "**"), damit sich der Text überall einfügen lässt.
    3. Erfinde nichts.

    Antworte ausschließlich mit einem JSON-Objekt der Form:
    {"text": "…"}

    Schreibe in der Sprache des Transkripts.
    PROMPT;
```

Wie beim bestehenden Prompt: im Admin-Dashboard änderbar
(`voice_quick_prompt`-Einstellung, gleiches Muster wie
`POSTPROCESS_PROMPT_KEY`). Ist die Nachbearbeitung insgesamt abgeschaltet
(`postprocessEnabled = false`), liefert `transcribeQuick()` den getrimmten
Rohtranskript-Text zurück — konsistent mit dem Fallback-Verhalten von
`refine()`.

### 5.3 Fehlerfälle

Gleiches Muster wie die bestehenden Endpunkte: `VoiceServiceException` → 502
`VOICE_SERVICE_FAILED`, leere Erkennung → 422 `VALIDATION_FAILED`,
Ratenlimit → 429 mit `Retry-After`. Der Kurzbefehl sollte auf den
HTTP-Statuscode verzweigen (Kurzbefehle-Aktion „Wenn") und bei Fehlschlag
einen anderen Ton abspielen statt eine Fehlermeldung in die Zwischenablage zu
kopieren.

### 5.4 Audit-Log

- `device_token_issued` / `device_token_revoked` beim Erzeugen/Widerrufen
  (wie bei Einladungen).
- **Kein** Audit-Log-Eintrag je einzelner Diktat-Anfrage — das wäre bei
  häufiger Nutzung reines Rauschen. `last_used_at` am Token reicht, um zu
  sehen, ob und wann er zuletzt benutzt wurde.

## 6. Einrichtung auf dem iPhone

### 6.1 Rückseitentipp

Einstellungen → Bedienungshilfen → Berühren → Rückseitentipp →
„Doppeltippen" → Kurzbefehl „NotesVoice" auswählen. Das ist eine
System-Zuordnung, keine Kurzbefehle-„Automation" im eigentlichen Sinn
(kein „Vor Ausführung fragen"-Dialog, läuft sofort).

### 6.2 Kurzbefehl „NotesVoice"

1. **Audio aufnehmen** — zeigt ein kompaktes Aufnahme-Overlay über der
   aktuellen App.
2. **Inhalte von URL abrufen** — `POST https://<host>/api/voice/quick`,
   Header `Authorization: Bearer <Token>`, Body: Formular mit Feld `audio` =
   Ergebnis aus Schritt 1.
3. **Wert aus Wörterbuch abrufen** — Schlüssel `text`.
4. **In Zwischenablage kopieren**.
5. **Ton abspielen** (kurz, statt einer Benachrichtigungsbanner — weniger
   aufdringlich, bestätigt aber hörbar den Abschluss).

Der Token wird als Textblock direkt im Kurzbefehl hinterlegt (Kurzbefehle
bietet keinen sicheren Schlüsselbund für einzelne Automationen). Das ist ein
bewusst akzeptiertes Restrisiko — begrenzt durch den engen Geltungsbereich
des Tokens (Abschnitt 4.1) und die Widerrufbarkeit in der App.

### 6.3 Bekannte Grenzen

- **Kein automatisches Stille-Ende.** Kurzbefehle stellt keine
  Spracherkennungs-Pause zur Verfügung; der Nutzer tippt „Fertig" im
  Overlay. Das ist der Preis dafür, dass nie zur App gewechselt wird — ein
  echtes Freihändig-Ende (z. B. per zweitem Rückseitentipp) ist mit
  Bordmitteln von Kurzbefehle nicht zuverlässig umsetzbar.
- **Erstlauf braucht eine Interaktion.** Die Mikrofonerlaubnis für
  Kurzbefehle muss einmalig manuell bestätigt werden (Systemdialog); danach
  läuft „Audio aufnehmen" ohne weitere Rückfrage.
- **Der Automations-Token liegt im Klartext im Kurzbefehl** und wird bei
  iCloud-Sync von Kurzbefehle mitsynchronisiert. Gegenmaßnahme: enger
  Geltungsbereich, Widerruf jederzeit möglich, kein Zugriff auf Notizen oder
  sonstige Daten.

## 7. Betroffene Komponenten

| Neu | Zweck |
|---|---|
| `database/migrations/0036_create_device_tokens.sql` | Tabelle für Automations-Token |
| `app/Repositories/DeviceTokenRepository.php` | Zugriff auf `device_tokens` |
| `app/Domain/Auth/DeviceTokenService.php` | Ausstellen/Auflisten/Widerrufen |
| `app/Middleware/DeviceTokenAuthMiddleware.php` | Bearer-Token → `user`-Attribut, nur an `/api/voice/quick` |
| `app/Controllers/DeviceTokenController.php` | `/api/profile/device-tokens` (index/store/destroy) |
| `resources/views/partials/device_token_panel.php` + zugehöriges JS | Token-Verwaltung im Profil, strukturell wie `invite_panel.php`/`userInvites.js` |

| Geändert | Zweck |
|---|---|
| `app/Domain/Voice/VoiceNoteService.php` | `transcribeQuick()`, `refineQuick()`, `DEFAULT_QUICK_PROMPT`, Setting `voice_quick_prompt` |
| `app/Controllers/VoiceNoteController.php` | Methode `quick()` |
| `app/Config/routes.php` | Route `POST /api/voice/quick`, Gruppe `/api/profile/device-tokens` |
| `app/Middleware/CsrfMiddleware.php` | Ausnahme für reine Bearer-Token-Anfragen (Abschnitt 4.4) |
| `app/Controllers/Admin/AdminDashboardController.php` | Neues Prompt-Feld im bestehenden Sprachnotizen-Formular |
| `docs/URS.md` | Neues Kapitel FR-NVOICE-01–08, API-Tabelle, Changelog-Eintrag |

Keine Änderung an `OPENAI_KEY`, `VOICE_TMP_PATH`, den bestehenden
`/api/voice/*`-Routen oder der Notiz-Diktat-Funktion — NotesVoice ist ein
zusätzlicher, unabhängiger Pfad auf derselben Infrastruktur.

## 8. Sicherheit — Zusammenfassung

- Automations-Token statt Session-Cookie: engster mögliche
  Geltungsbereich (eine Route, keine Notiz-/Workspace-Zugriffe).
- Token nur gehasht gespeichert, Rohwert einmalig sichtbar — gleiches
  Prinzip wie Sessions.
- Jederzeit widerrufbar; Widerruf wirkt sofort.
- Ratenbegrenzung pro Nutzer (Kostenschutz beim Anbieter), unabhängig vom
  Token selbst.
- Es entsteht kein Datenbankeintrag mit dem diktierten Inhalt — der Text
  verlässt den Server nur in der HTTP-Antwort und existiert serverseitig
  nur für die Dauer der Anfrage (Audiodatei wird wie bei den bestehenden
  Sprachnotizen sofort nach der Transkription gelöscht).
- CSRF-Ausnahme ist eng begründet (nur bei Bearer-Auth ohne Session-Cookie)
  und ändert nichts am Schutz für alle cookie-authentifizierten Routen.

## 9. Offene Produktentscheidungen

1. **Antwortformat**: JSON mit `text`-Feld (konsistent mit übriger API,
   braucht in Kurzbefehle einen zusätzlichen „Wert aus Wörterbuch
   abrufen"-Schritt) oder reiner `text/plain`-Body (ein Schritt weniger in
   der Automation, dafür ein Sonderfall in der API). Vorschlag: JSON, der
   eine Schritt-Unterschied ist in Kurzbefehle vernachlässigbar.
2. **Zusätzliches Sichern als Notiz**: optionaler Query-Parameter
   (`?save=1`), der zusätzlich zum Zwischenablage-Text wie
   `POST /api/voice/notes` eine Notiz anlegt — als Netz, falls die
   Zwischenablage vor dem Einfügen überschrieben wird. In Version 1 bewusst
   weggelassen (Ziel war ausdrücklich „nur Zwischenablage"), ließe sich aber
   ohne Bruch nachrüsten, da `createNote()` bereits existiert.
3. **Tonrückmeldung bei Fehlern**: eigener Fehlerton in der Automation
   sinnvoll, aber Geschmackssache — müsste beim Einrichten pro Nutzer
   festgelegt werden, nicht Teil des Server-Konzepts.
4. **Ablauf der Token**: derzeit kein TTL vorgesehen (siehe 4.1); falls
   gewünscht, ließe sich ein optionales Ablaufdatum beim Erzeugen ergänzen.

## 10. Teststrategie

- **PHPUnit**: `DeviceTokenService` (Ausstellen, Hash-Vergleich, Widerruf
  sperrt sofort), `DeviceTokenAuthMiddleware` (gültiger/ungültiger/
  widerrufener Token, kein Header), `VoiceNoteController::quick()` als
  Integrationstest mit demselben Fake-HTTP-Client-Muster wie die
  bestehenden Voice-Tests (Transkription + Nachbearbeitung liefert
  erwarteten `text`).
- **Manuell auf dem Gerät**: Mikrofonerlaubnis beim Erstlauf, Rückseitentipp
  löst den Kurzbefehl aus, Text landet korrekt in der Zwischenablage, kein
  App-Wechsel sichtbar, Widerruf des Tokens in der App blockiert die
  nächste Anfrage sofort (401).

## 11. Abnahmekriterien

- Doppeltipp auf die Geräterückseite löst „NotesVoice" aus, ohne dass die
  zuvor aktive App den Vordergrund verlässt.
- Der aufbereitete Text liegt nach Abschluss in der Zwischenablage und
  enthält keine Markdown-Sonderzeichen.
- Ohne aktiven, nicht widerrufenen Automations-Token liefert
  `POST /api/voice/quick` 401.
- Ein widerrufener Token wird bei der nächsten Anfrage sofort abgelehnt.
- `POST /api/voice/quick` legt unter keinen Umständen eine Seite, Notiz
  oder sonstige Ressource im Workspace an.
- Alle bestehenden `/api/voice/*`-Endpunkte und das Diktat in offene
  Notizen funktionieren unverändert.
