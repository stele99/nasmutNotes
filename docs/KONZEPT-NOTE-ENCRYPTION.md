# Konzept: Zero-Knowledge-Verschlüsselung einzelner Notizseiten (FR-CRYPT)

| Feld | Wert |
|---|---|
| Status | Überarbeiteter Konzeptentwurf, noch nicht implementiert |
| Datum | 2026-07-30 |
| Bezug | URS-NOTES-001, FR-NOTE-02/04/05/09/25, FR-OFFLINE-01 bis -05, FR-SHR, FR-EXP, FR-VOICE, NFR-SRCH |

---

## 1. Ziel und Geltungsbereich

Eine **normale Notizseite** kann vom Eigentümer per Schloss-Symbol verschlüsselt
werden. Ver- und Entschlüsselung des Notizinhalts passieren ausschließlich im
Browser. Der Server erhält für eine verschlüsselte Seite nur einen
authentifizierten Krypto-Umschlag und kann dessen Nutzinhalt nicht entschlüsseln.
Kennwort, KEK, DEK und entschlüsselter Dokumentinhalt verlassen das Gerät nicht.

Die Funktion gilt in Version 1 ausschließlich für Seiten des Typs `note` ohne
Bilder, Dateianhänge oder aktive Kopierfreigabe. Titel, Notizbuchzuordnung, Standort,
Seitenmetadaten und Zugriffsmetadaten bleiben unverschlüsselt.

Die Einführung ist **keine rückwirkende Löschgarantie**. Klartext, der vor dem
Verschlüsseln bereits in Browser-Caches, Exporten, Backups, Storage-Snapshots
oder auf dem Datenträger vorhanden war, kann nicht allein durch den
Verschlüsselungswechsel zuverlässig vernichtet werden. Das Schutzversprechen
gilt für den nach erfolgreichem Übergang gespeicherten aktuellen Inhalt und für
danach erstellte, bereinigte Datenbankabzüge.

## 2. Begriffe und Sicherheitsinvarianten

| Begriff | Bedeutung |
|---|---|
| DEK | Zufälliger 256-Bit-Schlüssel zur Verschlüsselung des Dokuments |
| KEK | Aus dem Kennwort abgeleiteter 256-Bit-Schlüssel zum Umhüllen des DEK |
| Umschlag | Versioniertes JSON mit KDF-Parametern, umhülltem DEK und verschlüsseltem Dokument |
| Klartextzustand | `pages.is_encrypted = 0` und `note_contents.content` ist gültiges ProseMirror-JSON |
| Kryptozustand | `pages.is_encrypted = 1` und `note_contents.content` ist ein gültiger Umschlag |

Nach der Migration gelten serverseitig jederzeit diese Invarianten:

1. `is_encrypted = 0` akzeptiert nur ein gültiges ProseMirror-Dokument.
2. `is_encrypted = 1` akzeptiert nur einen gültigen Krypto-Umschlag.
3. Bei `is_encrypted = 1` ist `note_contents.content_text` leer.
4. Bei `is_encrypted = 1` existieren keine `note_versions` der Seite.
5. Bei `is_encrypted = 1` existieren keine aktiven `read_copy`-Share-Links;
   `read` und `write` sind mit separater Kennwortübermittlung erlaubt.
6. Bei `is_encrypted = 1` existieren keine Einträge in `note_attachments` oder
   `page_attachments`.
7. Verschlüsselungsübergänge darf ausschließlich der Seiteneigentümer auslösen.
8. Ein normaler Save darf den Verschlüsselungszustand nicht ändern.
9. Verschlüsselte Inhalte werden clientseitig niemals als Klartext in
   `localStorage`, IndexedDB, Cache Storage oder der Offline-Outbox abgelegt.

Verletzungen dieser Invarianten werden nicht still repariert. Der Server lehnt
die Operation mit einem stabilen Fehlercode ab; Diagnose und Bereinigung müssen
explizit erfolgen.

## 3. Bedrohungsmodell

### 3.1 Schutzwirkung

Nach erfolgreicher Verschlüsselung schützt die Funktion den aktuellen
Notizinhalt gegen:

- Lesen eines aktuellen logischen Datenbankabzugs oder eines danach erzeugten
  bereinigten Backups
- lesenden Zugriff durch Administratoren oder den Hosting-Anbieter
- versehentliche Ausgabe des Inhalts durch serverseitige Suche, Export,
  Versionsansicht oder öffentliche Freigabe
- Veränderung einzelner Ciphertextbytes, IVs oder KDF-Felder, soweit diese durch
  AES-GCM beziehungsweise die strikte Umschlagvalidierung abgedeckt sind

### 3.2 Kein Schutz

Die Funktion schützt nicht gegen:

- einen aktiv bösartigen Server, der manipuliertes JavaScript ausliefert
- Cross-Site-Scripting oder kompromittierte eigene Build-Artefakte
- kompromittierte Endgeräte, Browser-Erweiterungen, Keylogger, Screenshots,
  Crash-Dumps oder Inhalte in der Zwischenablage
- schwache Kennwörter und Offline-Wörterbuchangriffe
- historische Klartextkopien in alten Backups, Exporten, Browser-Speichern,
  Storage-Snapshots, WAL-Dateien, SSD-Remanenz oder Copy-on-write-Dateisystemen
- absichtliches Zurückspielen eines vollständigen alten Datenbank- und
  Browserzustands; ein absoluter Rollback-Schutz ist ohne externen
  Vertrauensanker nicht möglich
- folgende Metadaten: Titel, Icon, Notizbuch, Favoritenstatus, Standort,
  Erstell- und Änderungszeit, Eigentümer, Ciphertextgröße und Zugriffsmuster

Der UI-Infotext muss diese Grenzen in verständlicher Form nennen. Die aktuelle
CSP verwendet Nonces, aber kein `strict-dynamic`. Sie reduziert allgemeine
Injektionsrisiken, kann einen bösartigen Betreiber jedoch prinzipbedingt nicht
abwehren.

## 4. Kryptographie

### 4.1 Primitive und feste Parameter für Formatversion 1

Alles läuft über die native WebCrypto API. Version 1 verwendet exakt folgende
Parameter:

| Zweck | Verfahren | Parameter |
|---|---|---|
| Zufall | `crypto.getRandomValues` | CSPRNG des Browsers |
| Kennwortbytes | UTF-8 | Unicode-Normalisierung NFC, kein `trim()` |
| Schlüsselableitung | PBKDF2-HMAC-SHA256 | exakt 600.000 Iterationen, 16-Byte-Salt |
| Inhaltsverschlüsselung | AES-256-GCM | 32-Byte-DEK, 12-Byte-IV, 16-Byte-Tag |
| DEK-Umhüllung | AES-256-GCM | 32-Byte-KEK, eigener 12-Byte-IV, 16-Byte-Tag |
| Binärkodierung | Base64 gemäß RFC 4648 | Standardalphabet mit `=`-Padding |

Ein späterer Formatstand darf andere Parameter oder Argon2id verwenden. Für
`zk = 1` sind die Werte jedoch exakt, damit manipulierte Iterationszahlen keinen
Client-DoS verursachen und jede Implementierung interoperabel bleibt.

Das Kennwort wird vor `TextEncoder` mit `normalize('NFC')` normalisiert. Führende
und abschließende Leerzeichen sind Bestandteil des Kennworts. Die UI darf weder
stillschweigend trimmen noch Groß-/Kleinschreibung verändern.

### 4.2 Kennwortregeln

- Mindestlänge: 12 Unicode-Codepoints; empfohlen wird eine lange Passphrase.
- Maximallänge: 1.024 UTF-8-Bytes nach NFC-Normalisierung.
- Einfügen und Passwortmanager sind ausdrücklich erlaubt.
- Ein lokaler Stärkehinweis warnt vor häufigen und offensichtlich schwachen
  Kennwörtern; er sendet keine Daten an den Server.
- Es gibt keine Wiederherstellung, kein serverseitiges Ersatzkennwort und keine
  Sicherheitsfrage.

Die Mindestlänge ist keine Sicherheitsgarantie. Da der Umschlag einen
Offline-Angriff erlaubt, muss der Dialog den Einsatz einer einzigartigen,
langen Passphrase empfehlen.

### 4.3 Schlüsselhierarchie und Schlüsselhaltung

Beim erstmaligen Verschlüsseln erzeugt der Client einen zufälligen 32-Byte-DEK.
Der DEK verschlüsselt das Dokument. Ein per PBKDF2 abgeleiteter KEK verschlüsselt
die rohen 32 DEK-Bytes. Danach wird der DEK als nicht extrahierbarer
`CryptoKey` für `AES-GCM` importiert; temporäre Bytearrays werden soweit in
JavaScript möglich mit Nullen überschrieben.

Kennwort, KEK, DEK und Klartext werden niemals absichtlich in
`localStorage`, `sessionStorage`, IndexedDB, Cache Storage oder Cookies
abgelegt. Sie verbleiben ausschließlich im Arbeitsspeicher des aktiven Tabs.
JavaScript und der Browser garantieren keine physische Speicherlöschung; das ist
eine Grenze des Bedrohungsmodells.

Der Client verwirft seine Referenzen auf DEK, KEK und Klartext:

- bei „Sperren"
- beim Verlassen oder Zerstören der Seite
- beim Logout
- wenn ein anderer Tab Verschlüsselungszustand oder Kennworthülle ändert
- nach einem nicht auflösbaren Krypto- oder Zustandsfehler

Version 1 besitzt keinen automatischen Leerlauf-Timer. Dieser kann später als
separate Produktfunktion ergänzt werden.

### 4.4 Bytegenaues Umschlagformat

`note_contents.content` enthält bei `is_encrypted = 1` genau dieses Schema:

```json
{
  "zk": 1,
  "binding": {
    "page_id": "123"
  },
  "kdf": {
    "algo": "PBKDF2-HMAC-SHA256",
    "iterations": 600000,
    "salt": "<base64, exakt 16 Byte>"
  },
  "wrapped_key": {
    "algo": "AES-256-GCM",
    "iv": "<base64, exakt 12 Byte>",
    "data": "<base64, exakt 48 Byte>"
  },
  "payload": {
    "algo": "AES-256-GCM",
    "iv": "<base64, exakt 12 Byte>",
    "data": "<base64, Ciphertext || 16-Byte-Tag>"
  }
}
```

`page_id` ist die dezimale Seiten-ID ohne Vorzeichen und führende Nullen. Die
JSON-Reihenfolge ist für die Kryptographie nicht relevant. Zusätzliche oder
fehlende Felder sind bei Version 1 unzulässig.

WebCrypto liefert bei AES-GCM `ciphertext || tag`; genau diese Bytes werden als
`data` Base64-kodiert. Das ProseMirror-Dokument wird mit
`JSON.stringify(document)` und anschließend `TextEncoder` in UTF-8-Bytes
überführt. Eine kanonische JSON-Serialisierung des Dokuments ist nicht nötig,
weil exakt die gespeicherten Bytes entschlüsselt werden.

### 4.5 Additional Authenticated Data

Beide AES-GCM-Operationen verwenden UTF-8-kodierte AAD:

```text
wrapped_key: nasmutNotes:zk:1:page:<page_id>:wrapped_key
payload:     nasmutNotes:zk:1:page:<page_id>:payload
```

Damit kann ein Umschlag nicht unbemerkt auf eine andere Seite kopiert werden.
Der Server prüft, dass `binding.page_id` der Zielseite entspricht. Beim Import
in eine neue Seite muss der Client nach erfolgreicher lokaler Entschlüsselung
mit der neuen Seiten-ID neu verschlüsseln.

AAD verhindert keinen vollständigen Rollback derselben Seite. Der normale
Versionszähler erkennt konkurrierende Änderungen, aber ein Angreifer mit
vollständiger Kontrolle über Datenbank und Versionen kann einen alten Zustand
zurückspielen. Diese Grenze wird nicht als gelöst dargestellt.

### 4.6 IV-Regel

Jede Verschlüsselung von Payload oder DEK verwendet einen neu erzeugten,
zufälligen 12-Byte-IV. Ein IV darf mit demselben Schlüssel nie wiederverwendet
werden. Kennwortwechsel erzeugt einen neuen Salt und einen neuen IV für
`wrapped_key`; der bestehende Payload bleibt unverändert. Jeder Autosave erzeugt
einen neuen Payload-IV.

### 4.7 Normative Testvektoren

Vor Implementierungsfreigabe wird im Repository eine maschinenlesbare Datei mit
mindestens zwei festen Testvektoren ergänzt. Jeder Vektor enthält Kennwort,
normalisierte Kennwortbytes, Seite, Salt, beide IVs, DEK, Dokumentbytes, AAD und
den vollständigen erwarteten Umschlag. Ein unabhängiges Referenzskript muss den
Umschlag ohne Anwendungscode entschlüsseln können. Erst dann darf das Dokument
„offen dokumentiert" und „ohne Lock-in" versprechen.

## 5. Datenmodell und Migration

### 5.1 Schema

Migration `0033_add_page_encryption.sql`:

```sql
ALTER TABLE pages
ADD COLUMN is_encrypted INTEGER NOT NULL DEFAULT 0
CHECK (is_encrypted IN (0, 1));
```

Das Flag liegt auf `pages`, damit Listen und Guards es ohne zusätzliche Abfrage
erhalten. Alle Page-Serialisierungen, Owner-Abfragen, Share-Abfragen und
Offline-Metadaten müssen das Feld konsistent als Boolean ausgeben.

### 5.2 Keine automatische Bestandsmigration

Vorhandene Notizen bleiben mit `is_encrypted = 0` im Klartextzustand. Die
Migration verschlüsselt nichts automatisch. Eine Seite wird erst durch einen
expliziten Eigentümer-Übergang verschlüsselt.

Falls `search_documents` oder `search_fts` aus Migration 0012 Daten enthalten,
müssen beim Verschlüsseln vorhandene Inhaltsdaten der Seite entfernt oder auf
Titel-only reduziert werden. Obwohl der aktuelle Suchpfad direkt
`content_text` verwendet, darf die ältere FTS-Struktur nicht als mögliche
Klartextkopie ignoriert werden.

## 6. Serverseitiger Zustandsautomat

### 6.1 Zulässige Operationen

| Operation | Ausgang | Ziel | Berechtigung |
|---|---|---|---|
| normaler Save | plain | plain | bisheriges Schreibrecht |
| verschlüsselter Save | encrypted | encrypted | Eigentümer oder Write-Share |
| verschlüsseln | plain | encrypted | Eigentümer |
| Kennworthülle ändern | encrypted | encrypted | Eigentümer |
| entschlüsseln | encrypted | plain | Eigentümer |

Nutzer mit Write-Share dürfen `plain -> plain` und `encrypted -> encrypted`
speichern. Sie müssen den Umschlag im Browser mit dem separat erhaltenen
Notizkennwort entsperren. Verschlüsseln, Kennwortwechsel und Entschlüsseln
bleiben ausschließlich dem Eigentümer vorbehalten.

### 6.2 Gemeinsamer Transaktionsablauf

Jeder Save und jeder Übergang folgt diesem Ablauf:

1. Grobe Autorisierung und Requestvalidierung ohne Zustandsänderung.
2. `BEGIN IMMEDIATE`, damit kein paralleler Writer den geprüften Zustand ändert.
3. Seite, aktuellen Inhalt, Version und Eigentümerstatus erneut lesen.
4. `expected_encryption_state` und `version` mit dem aktuellen Zustand und der
   aktuellen Inhaltsversion vergleichen.
5. Für `plain -> encrypted` innerhalb des Locks aktive `read_copy`-Shares sowie
   beide Anhangstabellen prüfen.
6. Inhalt passend zum Zielzustand validieren.
7. Inhalt, `content_text`, `is_encrypted`, Version und Zeitstempel atomar
   aktualisieren.
8. Bei `plain -> encrypted` alle `note_versions` und eventuell vorhandene
   Suchdokument-Inhalte löschen.
9. Commit; erst danach antworten.

Die Share-Erzeugung verhindert für verschlüsselte Seiten atomar `read_copy`,
erlaubt aber `read` und `write`. Beide Uploadpfade müssen unter einem
Schreiblock weiterhin `is_encrypted = 0` prüfen. Nur so sind Upload und
Verschlüsseln gegenseitig ausgeschlossen.

### 6.3 Validierung des Umschlags

`NoteCryptoEnvelope` validiert vor der Speicherung:

- exakte Feldstruktur ohne unbekannte Felder
- `zk === 1`
- `binding.page_id` entspricht der Zielseite
- exakte Algorithmusnamen und exakt 600.000 Iterationen
- kanonisches RFC-4648-Base64 mit Padding
- dekodierte Salt-, IV-, Wrapper- und Taglängen
- Payload enthält mindestens den 16-Byte-GCM-Tag
- äußerer JSON-Inhalt überschreitet 1.000.000 Byte nicht
- dekodierter Ciphertext überschreitet das festgelegte Klartextmaximum plus Tag
  nicht

Der Server führt keine KDF und keine Entschlüsselung aus. Eine strukturell
gültige, aber kryptographisch unbrauchbare Hülle bleibt Verantwortung des
Clients; AES-GCM erkennt sie beim Entsperren.

### 6.4 ProseMirror- und Größenvalidierung

Für Klartextzustände bleibt `ProseMirrorValidator` verbindlich. Beim
`encrypted -> plain`-Übergang wird das vom Client gelieferte Dokument wie jeder
andere Klartext-Save vollständig validiert und `content_text` neu erzeugt.

Die UI zeigt das verbleibende Klartextlimit nicht als pauschale Schätzung von
700 KB. Der Client berechnet vor dem Speichern die tatsächliche Umschlaggröße.
Der Server entscheidet ausschließlich anhand des kodierten Requests und der
dekodierten Grenzwerte.

### 6.5 Versionen und Konflikte

Verschlüsselte Seiten erzeugen keine `note_versions`; Liste, Anzeige und Restore
lehnen serverseitig mit `NOTE_ENCRYPTED` ab. Beim Verschlüsseln werden vorhandene
Versionen in derselben Transaktion logisch gelöscht.

Der normale Inhaltsversionszähler bleibt erhalten und wird bei jedem
verschlüsselten Save, Kennwortwechsel und Entschlüsseln erhöht. Ein 409 enthält
den aktuellen Umschlag und Zustand, niemals serverseitig entschlüsselten Inhalt.
Die Client-Konfliktoberfläche darf Umschläge erst nach lokaler Entschlüsselung
als Dokumente vergleichen.

## 7. API-Vertrag

Der bestehende Content-Endpunkt bleibt für normale Saves erhalten, bekommt aber
explizite Zustandsfelder. Ein boolesches `encrypt` genügt nicht.

### 7.1 Normaler oder verschlüsselter Save

```json
PUT /api/pages/{id}/content
{
  "content": {},
  "version": 7,
  "expected_encryption_state": "plain",
  "force_snapshot": false
}
```

`expected_encryption_state` ist verpflichtend und lautet `plain` oder
`encrypted`. Der Endpunkt darf den Zustand nicht ändern.

### 7.2 Zustandsübergang

```json
PUT /api/pages/{id}/content/encryption
{
  "transition": "encrypt",
  "content": {},
  "version": 7,
  "expected_encryption_state": "plain"
}
```

`transition` ist genau einer dieser Werte:

- `encrypt`: ProseMirror-Klartext wird durch einen Umschlag ersetzt.
- `rewrap`: Zustand bleibt verschlüsselt; vollständiger neuer Umschlag mit
  unverändertem Payload wird gespeichert.
- `decrypt`: Umschlag wird durch ProseMirror-Klartext ersetzt.

Der separate Übergangsendpunkt erleichtert Autorisierung, Rate Limits, Audit,
Outbox-Modellierung und Tests. `rewrap` muss denselben Payload bytegenau
beibehalten; der Server vergleicht `payload.iv` und `payload.data` mit dem
aktuellen Umschlag.

### 7.3 Antworten und Fehlercodes

Erfolgreiche Antworten enthalten mindestens:

```json
{
  "content": {},
  "version": 8,
  "encryption_state": "encrypted",
  "updated_at": "2026-07-30T12:00:00.000Z",
  "last_editor_name": "Name"
}
```

| HTTP | Code | Bedeutung |
|---|---|---|
| 403 | `ENCRYPTION_OWNER_REQUIRED` | Übergang nicht vom Eigentümer |
| 409 | `VERSION_CONFLICT` | Inhaltsversion veraltet |
| 409 | `ENCRYPTION_STATE_CONFLICT` | Ausgangszustand veraltet |
| 409 | `ENCRYPTION_HAS_COPY_SHARE` | aktive Kopierfreigabe vorhanden |
| 409 | `ENCRYPTION_COPY_UNAVAILABLE` | Kopierfreigabe für verschlüsselte Seite angefordert |
| 409 | `ENCRYPTION_HAS_ATTACHMENTS` | Bild oder Dateianhang vorhanden |
| 409 | `ENCRYPTION_REWRAP_PAYLOAD_CHANGED` | `rewrap` verändert den Payload |
| 422 | `INVALID_CRYPTO_ENVELOPE` | Umschlag strukturell ungültig |
| 422 | `INVALID_NOTE_CONTENT` | Klartextdokument ungültig |
| 422 | `CONTENT_TOO_LARGE` | Größenlimit überschritten |
| 422 | `NOTE_ENCRYPTED` | Funktion für Kryptozustand unzulässig |

Antworten mit Notizinhalt oder Krypto-Umschlag setzen `Cache-Control: no-store`.
Der explizite Offline-Cache speichert Umschläge kontrolliert in IndexedDB; ein
zusätzlicher impliziter HTTP-Cache des Browsers oder eines Proxys ist nicht
erwünscht. Service Worker und API-Client dürfen Inhaltsantworten nicht in einen
allgemeinen Shell-Cache übernehmen.

## 8. Client-Abläufe

### 8.1 Verschlüsseln

1. Schloss ist nur für den Eigentümer einer normalen Notiz aktiv; vorhandene
   Lese- oder Schreibfreigaben sind zulässig, Kopierfreigaben nicht.
2. Vor dem Dialog stoppt der Client Autosave und erwirbt den bestehenden
   Edit-Lock.
3. Der Client prüft lokale Outbox und Konflikte für die Seite.
4. Ungesyncte Klartextänderungen müssen online synchronisiert oder nach
   ausdrücklicher Bestätigung verworfen werden. Offline ist der Übergang in
   Version 1 nicht erlaubt.
5. Dialog verlangt Kennwort zweimal und eine Pflichtbestätigung zum endgültigen
   Datenverlust bei Kennwortverlust.
6. Client erzeugt DEK, Umschlag und AAD für die konkrete Seiten-ID.
7. Client sendet `transition = encrypt` mit erwarteter Version und Zustand.
8. Nach Erfolg ersetzt er IndexedDB-Inhalt und Seitenzustand durch den Umschlag,
   entfernt den alten `localStorage`-Entwurf und verwirft alte Konfliktdaten.
9. Andere Tabs erhalten per `BroadcastChannel` ein Ereignis und sperren die
   Seite beziehungsweise laden den neuen Zustand.
10. Editor bleibt im auslösenden Tab entsperrt; nur der Umschlag wird ab jetzt
    persistiert.

### 8.2 Öffnen und Entsperren

Der Client lädt oder liest aus IndexedDB ausschließlich den Umschlag. Statt des
Editors erscheint die Sperrmaske. Vor PBKDF2 validiert der Client alle
Umschlagparameter und Größen.

Nach Kennworteingabe leitet er den KEK ab, entschlüsselt den DEK und danach den
Payload. Falsches Kennwort, beschädigte Hülle und GCM-Authentifizierungsfehler
erhalten gegenüber dem Nutzer dieselbe Meldung. Struktur- und Parameterfehler
werden vorher als beschädigte oder nicht unterstützte Notiz gemeldet. Eine
konstante Laufzeit aller Fehlerpfade wird nicht versprochen.

Nach erfolgreicher Entschlüsselung validiert und sanitisiert der Client das
Dokument mit dem Editor-Schema. Erst danach wird der Editor initialisiert.

### 8.3 Autosave

Bei jeder Änderung liest der Client das Dokument aus dem Editor, verschlüsselt
es mit neuem Payload-IV und übergibt **nur den neuen Umschlag** an
`saveNoteOffline`. Klartext darf weder `writeLocalCache` noch IndexedDB oder die
Outbox erreichen.

Der lokale Notfallcache für verschlüsselte Seiten enthält nur:

- Umschlag
- Serverversion und lokalen Revisionszähler
- erwarteten Verschlüsselungszustand
- Zeitstempel

### 8.4 Sperren

Sperren pausiert Autosave, wartet einen laufenden Verschlüsselungsvorgang ab und
zerstört anschließend Editor und Undo-History. Alle erreichbaren Referenzen auf
Dokument, Kennwort, KEK und DEK werden verworfen. Die IndexedDB-Fassung bleibt
als Umschlag offline verfügbar.

### 8.5 Kennwort ändern

Kennwortwechsel ist nur online und im entsperrten Zustand erlaubt:

1. Autosave vollständig abschließen und pausieren.
2. Aktuelle Hülle und Version laden.
3. Neues Kennwort zweimal erfassen.
4. Neuen Salt und KEK erzeugen, denselben DEK mit neuem IV umhüllen.
5. `transition = rewrap` senden; Payload bleibt bytegenau identisch.
6. Nach Erfolg alle lokalen Umschläge ersetzen und andere Tabs sperren.

Ein veralteter Tab kann den alten Wrapper wegen Versions- und Zustandsprüfung
nicht zurückschreiben.

### 8.6 Verschlüsselung aufheben

Entschlüsseln ist nur online und im entsperrten Zustand erlaubt. Nach deutlicher
Bestätigung sendet der Client das ProseMirror-Dokument mit
`transition = decrypt`. Ab diesem Zeitpunkt kann der Server den Inhalt wieder
lesen. Der Server validiert das Dokument, erzeugt `content_text`, setzt das Flag
zurück und beginnt die Versionshistorie erst bei späteren Saves neu.

Nach Erfolg ersetzt der Client lokale Umschläge durch den Klartextzustand. Der
Dialog weist darauf hin, dass künftige Backups, Suche, Export und KI den Inhalt
wieder im Klartext verarbeiten können.

## 9. Offline- und Multi-Tab-Modell

### 9.1 Outbox-Schema

Jeder Notiz-Outboxeintrag erhält zusätzlich:

```json
{
  "operation": "save",
  "expected_encryption_state": "encrypted",
  "content": {},
  "version": 7
}
```

Zulässige Operationen sind `save`, `encrypt`, `rewrap` und `decrypt`. Version 1
queued `encrypt`, `rewrap` und `decrypt` nicht offline; das Schema kennt sie
trotzdem, damit Zustandsinformationen beim Coalescing nicht verloren gehen.

Outboxeinträge dürfen nur zusammengeführt werden, wenn Seite, Operation und
erwarteter Verschlüsselungszustand kompatibel sind. Ein Klartext-Save darf nie
mit einem verschlüsselten Save zusammengeführt oder nach einem erfolgreichen
`encrypt` erneut gesendet werden.

### 9.2 Konflikte

Bei einem Zustandskonflikt blockiert die Outbox den Eintrag. Sie bietet kein
automatisches „Meine Fassung behalten" über eine Zustandsgrenze hinweg.

Bei zwei verschlüsselten Fassungen kann der Client beide lokal entschlüsseln,
wenn der gehaltene DEK passt. Andernfalls muss die Serverfassung neu entsperrt
werden. Die Konfliktansicht zeigt niemals Ciphertext als leere oder vermeintlich
gültige ProseMirror-Notiz.

### 9.3 Tab-Koordination

Ein `BroadcastChannel` meldet mindestens:

- `encryption-state-changed`
- `encryption-wrapper-changed`
- `encryption-locked`

Andere Tabs stoppen Autosave und verwerfen ihren DEK, sobald Zustand oder
Wrapper geändert wurde. Der Server bleibt die letzte Instanz; Tab-Koordination
ersetzt keine Versionsprüfung.

### 9.4 Lokale Bestandsbereinigung

Beim erfolgreichen Verschlüsseln werden für die Seite entfernt oder ersetzt:

- `notes-note-cache-{pageId}` in `localStorage`
- Klartext in IndexedDB `note_contents`
- Klartext-Outboxeinträge und Konfliktkopien
- gecachtes Seiten-HTML, falls es eine alte Klartextvorschau enthält

Browserdatenbanken garantieren keine forensisch sichere Löschung. Die Maßnahme
verhindert normalen Zugriff über die Anwendung, ist aber keine physische
Datenträgerbereinigung.

## 10. Serverseitig gesperrte Funktionen

Für `is_encrypted = 1` werden serverseitig abgelehnt:

| Funktion | Verhalten |
|---|---|
| Lese-Share | erlaubt; öffentliche Ansicht liefert den Umschlag und entschlüsselt nach Kennworteingabe ausschließlich im Browser |
| Write-Share | erlaubt; angemeldete Empfänger speichern ausschließlich neue Umschläge |
| Verschlüsselungsübergänge durch Share-Empfänger | ablehnen; nur Eigentümer darf Encrypt, Rewrap und Decrypt auslösen |
| Copy-Share und serverseitiges Duplizieren | ablehnen; kein blindes Kopieren des seitengebundenen Umschlags |
| KI-Überarbeitung | vor Validierung oder Textverarbeitung ablehnen |
| Bild- und Dateiupload | vor Dateischreiben und nochmals unter Schreiblock ablehnen |
| Versionsliste, Versionsdetail, Restore | 422 `NOTE_ENCRYPTED` |
| serverseitiger Markdown-Export | definierter Ciphertext-Export oder sichtbarer Ausschluss, niemals leere Datei |
| serverseitige Inhaltsvorschau | fester Text „Verschlüsselte Notiz" |
| Inhalts-Volltextsuche | nur Titel und erlaubte Metadaten durchsuchen |

Lesen und Löschen inkonsistent verbliebener Anhänge darf für den Eigentümer
möglich bleiben, damit ein beschädigter Zustand bereinigt werden kann. Der
Admin-Orphan-Purge überspringt Anhänge verschlüsselter Seiten defensiv.

Das Notizkennwort ist niemals Bestandteil des Share-Links und wird nicht über
die Anwendung an Empfänger verteilt. Der Eigentümer übermittelt es über einen
separaten, geeigneten Kanal. Der Server kann weder Kennwort noch Klartext sehen.

Weiterhin erlaubt sind Metadatenoperationen wie Umbenennen, Verschieben in ein
anderes Notizbuch, Favorisieren, Standort ändern, in den Papierkorb legen und
endgültig löschen. Diese Operationen ändern den Umschlag nicht. Beim endgültigen
Löschen gelten dieselben Grenzen für WAL, Browserreste und alte Backups wie beim
Verschlüsseln. Ein serverseitiges Duplizieren bleibt gesperrt, weil der Umschlag
an die Seiten-ID gebunden ist.

### 10.1 Diktat

Der bestehende Transkriptionsendpunkt besitzt keine Seiten-ID und kann den
Kryptozustand nicht serverseitig prüfen. Für Version 1 sendet das Diktat in eine
geöffnete Notiz deshalb verpflichtend `page_id`; der Server prüft Zugriff,
Seitentyp und `is_encrypted = 0`, bevor Audiodaten an den Anbieter gehen.

Die allgemeine Funktion „neue Sprachnotiz erstellen" bleibt davon unberührt,
weil noch keine verschlüsselte Zielseite existiert.

## 11. Suche, Vorschauen und Metadaten

Beim Verschlüsseln wird `content_text` atomar geleert. `SearchRepository` kann
danach nur noch den Titel treffen. Eventuelle `search_documents`-Einträge der
Seite werden auf Titel-only reduziert und der FTS-Index wird entsprechend
aktualisiert oder neu aufgebaut.

Seitenlisten und Sidebar zeigen für verschlüsselte Seiten unabhängig von
eventuell veralteten Previewfeldern ausschließlich „Verschlüsselte Notiz". Die
UI darf nicht auf die bloße Leerheit von `content_text` vertrauen, sondern nutzt
`is_encrypted`.

Admin- und Speicherstatistiken dürfen Ciphertextgröße und Titel weiterhin
anzeigen; dies ist als verbleibendes Metadatum dokumentiert. Optional kann eine
spätere Datenschutzstufe Einzeltitel in Admin-Toplisten ausblenden.

## 12. Export und Import

### 12.1 Workspace-Export

Ein Workspace-Export schreibt verschlüsselte Seiten nicht als leere
Markdown-Datei. Version 1 exportiert stattdessen:

```text
<Seitentitel>.encrypted-note.json
```

Die Datei enthält den vollständigen Umschlag sowie unverschlüsselte
Exportmetadaten wie Titel und ursprüngliche Seiten-ID. Das Archiv und der
Dateiname werden deutlich als verschlüsselt gekennzeichnet.

Temporäre serverseitige Exportarchive werden nach Auslieferung beziehungsweise
nach einem kurzen dokumentierten Ablaufintervall gelöscht. Bereits vom Nutzer
heruntergeladene ältere Klartextexporte liegen außerhalb der Kontrolle der
Anwendung und werden durch späteres Verschlüsseln nicht ungültig.

### 12.2 Klartext-Export

Ein Klartext-Markdown-Export ist nur clientseitig nach Entsperren möglich. Der
Browser erzeugt die Datei lokal; der Klartext wird nicht an einen Exportendpunkt
gesendet. Diese Funktion kann nach der Basisimplementierung geliefert werden,
darf aber nicht durch einen serverseitigen Leerexport ersetzt werden.

### 12.3 Reimport

Ein verschlüsselter Export wird im Browser eingelesen und mit dem Kennwort
entschlüsselt. Nach lokaler ProseMirror-Validierung wird eine neue Seite
angelegt und der Inhalt für deren neue Seiten-ID neu verschlüsselt. Ein blindes
serverseitiges Kopieren ist wegen der AAD-Seitenbindung unzulässig.

Ein Roundtrip-Test `encrypt -> export -> import -> decrypt` ist verpflichtend,
bevor „kein Lock-in" zugesichert wird.

## 13. Altdaten, WAL und Backups

### 13.1 Logische Bereinigung beim Übergang

Innerhalb der Verschlüsselungstransaktion werden:

- `note_contents.content` durch den Umschlag ersetzt
- `note_contents.content_text` geleert
- alle `note_versions` der Seite gelöscht
- mögliche Suchdokument-Inhalte entfernt

Vor den betreffenden Updates und Deletes ist für die Verbindung
`PRAGMA secure_delete = ON` gesetzt. Dies reduziert Klartextreste in normalen
SQLite-Freelist-Seiten, ist aber keine vollständige physische Löschgarantie.

### 13.2 WAL und aktive Datenbank

`secure_delete` bereinigt keine bereits vorhandenen WAL-Frames. Ein optionaler
administrativer Bereinigungslauf muss außerhalb der Request-Transaktion und in
einem exklusiven Wartungsfenster:

1. neue Schreibzugriffe stoppen,
2. einen vollständigen WAL-Checkpoint mit Truncation durchführen,
3. `VACUUM` auf der aktiven Datenbank ausführen,
4. das Ergebnis und einen anschließenden Integritätscheck protokollieren,
5. Schreibzugriffe wieder freigeben.

Der Lauf ist betriebsrelevant und darf nicht als normaler Teil des Autosaves
ausgeführt werden. Auch danach können Betriebssystem, SSD oder
Storage-Snapshots ältere Blöcke behalten.

### 13.3 Backups und Restore

Bestehende Backups vor dem Verschlüsselungszeitpunkt können Klartext enthalten.
Der Verschlüsselungsdialog nennt diesen Umstand, wenn serverseitige Backups
aktiv sind. Die Betriebsdokumentation legt eine Retention fest und bietet
Administratoren eine bewusste Löschung alter Snapshots an.

Ein Restore eines alten Backups kann Klartext und `is_encrypted = 0`
wiederherstellen. Restore-UI und CLI warnen davor ausdrücklich. Nach Restore
müssen Migrationen und Konsistenzprüfungen laufen; die Anwendung darf nicht
behaupten, der frühere Kryptozustand bleibe über den Restore hinweg erhalten.

Neu erzeugte `VACUUM INTO`-Backups nach erfolgreicher Verschlüsselung enthalten
für die aktuelle Seite nur den Umschlag. Historische Backupstände bleiben davon
unverändert.

## 14. Audit und Logging

Erfolgreiche Übergänge werden mit Nutzer, Seiten-ID, altem/neuem Zustand und
Zeitpunkt protokolliert:

- `note_encryption_enabled`
- `note_encryption_rewrapped`
- `note_encryption_disabled`

Audit- und Anwendungslogs enthalten niemals Kennwort, Salt, IV, DEK, KEK,
Umschlag, Klartext oder Requestbody. Lokale Fehlversuche beim Entsperren werden
nicht an den Server gemeldet. Rate Limiting schützt nur die Übergangsendpunkte,
nicht vor Offline-Kennwortangriffen.

## 15. UI und Barrierefreiheit

Das Schloss erscheint im rechten Aktionsbereich des Seitenkopfs, nicht als nur
visuell verschobenes Element innerhalb der schmalen Standortzeile. Die genaue
Position wird zusammen mit dem responsiven Headerlayout umgesetzt.

Es gibt drei verständlich beschriftete Zustände:

- „Notiz unverschlüsselt"
- „Notiz verschlüsselt und entsperrt"
- „Notiz verschlüsselt und gesperrt"

Icon, Text beziehungsweise Tooltip und `aria-label` müssen übereinstimmen. Der
Zustand darf nicht ausschließlich über Farbe vermittelt werden. Dialoge sind
per Tastatur bedienbar, halten den Fokus und schließen nicht während laufender
Krypto- oder Speicheroperationen.

## 16. Betroffene Komponenten

| Komponente | Änderung |
|---|---|
| `database/migrations/0033_add_page_encryption.sql` | Flag mit CHECK-Constraint |
| `app/Domain/Notes/NoteCryptoEnvelope.php` | strikter Umschlagvalidator |
| `app/Domain/Notes/NoteService.php` | Zustandsautomat, Transaktion, Purge, Guards |
| `app/Controllers/NoteController.php` | Save-Vertrag und Übergangsendpunkt |
| Page-/Share-Repositories und Services | Flag serialisieren, Read/Write erlauben, Copy atomar sperren |
| Attachment-Services | Guard vor Dateischreiben und unter Schreiblock |
| AI- und Voice-Services | früher Kryptoguard, Voice mit `page_id` |
| Versions-, PublicShare- und Copy-Service | defensive Guards |
| Export-/Import-Service | Ciphertextformat und clientseitiger Roundtrip |
| Backup-Tooling | Warnungen, Retention und optionaler Bereinigungslauf |
| `resources/js/noteCrypto.js` | WebCrypto, Format, AAD und Validierung |
| `resources/js/notePage.js` | Lock/Unlock, Dialoge, verschlüsselter Autosave |
| `resources/js/offline/*` | Zustandsfelder, sichere Outbox, Cachebereinigung |
| Seitenkopf und Sidebar | Schlosszustände und feste Vorschau |
| Audit und Admin-Purge | Übergangsereignisse und defensive Anhangsbehandlung |
| URS | FR-CRYPT und Ausnahmen bestehender Anforderungen |

## 17. Teststrategie

### 17.1 Kryptographie

- normative Testvektoren in Browser und unabhängigem Referenzskript
- korrektes Roundtrip für ASCII, Umlaute, Emoji, kombinierende Zeichen und
  führende beziehungsweise abschließende Leerzeichen
- falsches Kennwort und manipulierte Tags
- falsche Seiten-AAD und ausgetauschte Umschläge
- ungültiges Base64, falsche Längen, unbekannte Felder und Versionen
- Iterationswerte unter und über exakt 600.000
- frischer IV bei jedem Save und Rewrap

### 17.2 Backend und Datenbank

- alle fünf Zustandsoperationen und unzulässige Übergänge
- Owner-only für Encrypt, Rewrap und Decrypt
- Leeren von `content_text`, Versions- und Suchdatenbereinigung
- verschlüsselte Read- und Write-Shares sowie Owner-only für Zustandsübergänge
- Copy-Share-vs-Encrypt- und Upload-vs-Encrypt-Race mit zwei DB-Verbindungen
- Migration auf leerer und befüllter Datenbank
- CHECK-Constraint und Konsistenzdiagnose

### 17.3 Offline und Browser

- kein Klartext in `localStorage`, IndexedDB, Outbox oder Cache Storage
- Klartext-Outbox vor dem Verschlüsseln
- Save während Lock, Rewrap und Seitenwechsel
- Multi-Tab-Rewrap und Zustandswechsel
- verschlüsselte Konflikte und Zustandskonflikte
- Offline-Lesen und Entsperren eines bereits gecachten Umschlags
- Offline-Schreiben und spätere Synchronisation ausschließlich als Umschlag
- Logout und Cachebereinigung

Diese Tests benötigen echte Browser- beziehungsweise Playwright-Tests; reine
PHPUnit-Tests und ein Vite-Build reichen nicht.

### 17.4 Export, Backup und Betrieb

- Ciphertext-Export erzeugt keine leere Markdown-Datei
- Roundtrip `encrypt -> export -> import -> decrypt`
- Backup nach Verschlüsselung enthält keinen aktuellen Klartext
- Restore vor und nach Migration sowie vor und nach Verschlüsselung
- WAL-Checkpoint-/VACUUM-Bereinigung in einer Testdatenbank
- dokumentierte Warnungen und Audit-Ereignisse

## 18. Abnahmekriterien

Die Funktion ist erst freigabefähig, wenn:

1. Umschlagformat und Testvektoren bytegenau feststehen.
2. Der Server alle Zustandsinvarianten atomar erzwingt.
3. Browser-Tests belegen, dass verschlüsselte Bearbeitung keinen Klartext
   persistent speichert.
4. Alte Klartext-Outboxeinträge keine verschlüsselte Seite überschreiben können.
5. Copy-Share- und Upload-Races getestet und geschlossen sind.
6. Export und Import verschlüsselte Seiten sichtbar und verlustfrei behandeln.
7. Backup-, Restore- und WAL-Grenzen in UI und Betriebsdokumentation genannt
   werden.
8. Die URS einen verbindlichen FR-CRYPT-Abschnitt und Ausnahmen für Suche,
   Versionierung, Export, Offline, KI und Diktat enthält.
9. Sicherheitsreview und Wiederherstellung mit dem unabhängigen
   Referenzskript erfolgreich waren.

## 19. Offene Produktentscheidungen

Vor Implementierungsbeginn sind noch ausdrücklich zu entscheiden:

1. Soll jede Notiz ein eigenes Kennwort haben oder folgt später ein lokaler
   Master-Key beziehungsweise ein verschlüsselter Schlüsselbund?
2. Wird clientseitiger Klartext-Markdown-Export bereits in Version 1 geliefert?
3. Darf Diktat nach deutlicher Warnung für verschlüsselte Notizen verwendet
   werden, oder bleibt es vollständig gesperrt?
4. Soll der Administrator alte Backups pro Verschlüsselungszeitpunkt gezielt
   löschen können?
5. Wird ein automatischer Leerlauf-Lock später standardmäßig aktiviert?
6. Sollen Titel und Standort in einer späteren Version ebenfalls verschlüsselt
   werden, obwohl dies Listen, Suche und Kartenfunktionen grundlegend ändert?
7. Soll eine spätere Version auch clientseitiges Kopieren aus einer
   verschlüsselten `read_copy`-Freigabe unterstützen?

Keine dieser Entscheidungen darf durch stilles Fallback oder implizites
Verhalten vorweggenommen werden.
