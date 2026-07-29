<div
    x-show="infoDialogOpen"
    x-cloak
    class="fixed inset-0 z-[130] flex items-center justify-center p-3 sm:p-5"
    style="background-color: rgb(0 0 0 / 0.5);"
    @click.self="infoDialogOpen = false"
    @keydown.escape.window="infoDialogOpen = false"
    role="dialog"
    aria-modal="true"
    aria-labelledby="info-dialog-title"
>
    <div class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <header class="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
            <div>
                <p class="text-sm font-medium" style="color: var(--color-accent);">Informationen zur Nutzung</p>
                <h2 id="info-dialog-title" class="mt-1 text-xl font-semibold">Datenschutz, Verantwortung &amp; Datensicherheit</h2>
            </div>
            <button type="button" @click="infoDialogOpen = false" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </header>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5 text-sm leading-relaxed sm:px-6">
            <section>
                <h3 class="font-semibold">Anbieter</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">nasmutNotes wird bereitgestellt durch:</p>
                <address class="mt-2 not-italic">
                    <strong>MAST Hard- &amp; Software</strong><br>
                    St.-Barbara-Weg 25<br>
                    78567 Fridingen
                </address>
                <p class="mt-3" style="color: var(--color-text-muted);">Der Herausgeber und Diensteanbieter übernimmt, soweit gesetzlich zulässig, keine Gewähr für die ununterbrochene Verfügbarkeit, Fehlerfreiheit oder Eignung des Dienstes für einen bestimmten Zweck und keine Haftung für Schäden, die aus der Nutzung oder Nichtverfügbarkeit des Dienstes entstehen.</p>
            </section>

            <section>
                <h3 class="font-semibold">Speicherung in Deutschland</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">Die Anwendungsdaten werden auf Servern in Deutschland gespeichert. Zugriff erhalten nur berechtigte Nutzer sowie die für Betrieb und Wartung erforderlichen Administratoren.</p>
            </section>

            <section>
                <h3 class="font-semibold">Sicherungen und Datenverlust</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">Die Datenbestände werden regelmäßig technisch gesichert. Sicherungen reduzieren das Risiko eines Verlusts, können aber keine lückenlose oder jederzeitige Wiederherstellung garantieren. Trotz angemessener Schutzmaßnahmen sind Ausfälle, technische Defekte, Fehlbedienung oder Datenverlust nicht vollständig auszuschließen.</p>
                <p class="mt-2" style="color: var(--color-text-muted);">Eine Haftung für Datenverlust oder daraus entstehende Schäden wird, soweit gesetzlich zulässig, ausgeschlossen. Nutzer können ihre Inhalte selbstständig exportieren und damit jederzeit eigene Backups erstellen. Besonders wichtige oder unersetzliche Inhalte sollten regelmäßig zusätzlich außerhalb von nasmutNotes gesichert werden.</p>
            </section>

            <section>
                <h3 class="font-semibold">Verantwortung für Inhalte</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">Für hochgeladene, eingegebene, importierte, geteilte oder gespeicherte Inhalte bleibt die jeweilige Person verantwortlich. Das gilt insbesondere für Richtigkeit, Rechtmäßigkeit, Urheber- und Nutzungsrechte sowie den zulässigen Umgang mit personenbezogenen oder vertraulichen Daten. Inhalte dürfen keine Rechte Dritter oder geltendes Recht verletzen.</p>
            </section>

            <section>
                <h3 class="font-semibold">Standort- und Kartendienste</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">Standortfunktionen sind optional. Wenn sie verwendet werden, verarbeitet die Anwendung Koordinaten, Suchbegriffe und gegebenenfalls daraus ermittelte Adressen. Für Karten, Adresssuche und die Auflösung von Koordinaten wird OpenStreetMap beziehungsweise der Dienst Nominatim genutzt.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5" style="color: var(--color-text-muted);">
                    <li>Kartenkacheln und Adressabfragen werden grundsätzlich über den Anwendungsserver abgerufen; der Browser verbindet sich dafür nicht direkt mit dem Kartendienst.</li>
                    <li>Suchbegriffe oder Koordinaten werden serverseitig an den Kartendienst übermittelt, soweit dies für die gewählte Funktion erforderlich ist.</li>
                    <li>Beim Öffnen eines externen OpenStreetMap-Links gelten die Datenschutzbedingungen des externen Anbieters; dabei wird eine direkte Verbindung hergestellt.</li>
                </ul>
            </section>

            <section>
                <h3 class="font-semibold">KI-Dienste</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">KI-Funktionen sind optional. Bei ihrer Nutzung werden die für die konkrete Aufgabe erforderlichen Inhalte an OpenAI oder an den administrativ konfigurierten kompatiblen KI-Dienst übertragen. Dazu können insbesondere Notiztexte, Diktat-Audiodaten, Transkripte, Logbuchspalten und die zu verarbeitenden Eingaben gehören.</p>
                <p class="mt-2" style="color: var(--color-text-muted);">KI-Ergebnisse können unvollständig, fehlerhaft oder missverständlich sein. Sie müssen vor der Übernahme eigenverantwortlich geprüft werden. Vertrauliche, besonders schützenswerte oder nicht zur Weitergabe bestimmte Informationen sollten nicht an KI-Funktionen übermittelt werden.</p>
            </section>

            <section>
                <h3 class="font-semibold">Anmeldung und externe Anbieter</h3>
                <p class="mt-1" style="color: var(--color-text-muted);">Bei einer Anmeldung über Google werden die für die Authentifizierung erforderlichen Kontodaten verarbeitet. Für die Verarbeitung durch Google und andere aufgerufene externe Dienste gelten ergänzend deren eigene Datenschutz- und Nutzungsbedingungen.</p>
            </section>

            <div class="rounded-lg border p-4" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                <p class="font-medium">Empfehlung</p>
                <p class="mt-1" style="color: var(--color-text-muted);">Nutze Freigaben, Standort- und KI-Funktionen bewusst, prüfe automatisch erzeugte Inhalte und bewahre von geschäftskritischen oder unersetzlichen Daten eine eigene Kopie auf.</p>
            </div>
        </div>

        <footer class="flex justify-end border-t px-5 py-4 sm:px-6" style="border-color: var(--color-border);">
            <button type="button" @click="infoDialogOpen = false" class="btn btn-primary">Verstanden</button>
        </footer>
    </div>
</div>
