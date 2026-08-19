<div class="mx-auto max-w-4xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminDashboard">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium" style="color: var(--color-accent);">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">KI-Einstellungen</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/admin" class="btn btn-secondary">Nutzer &amp; Speicher</a>
            <a href="/app" class="btn btn-secondary">Zum Workspace</a>
        </div>
    </div>

    <p x-show="error" x-cloak x-text="error" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="alert"></p>
    <p x-show="message" x-cloak x-text="message" class="mb-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-success) 14%, transparent);" role="status"></p>

    <div class="document-surface mb-8 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Sprachnotizen und Logbuch-Diktat</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Transkription, KI-Nachbearbeitung, Notizbuchvorschläge und die Zuordnung diktierter Logbuchwerte. Aktuell: <span class="font-medium" style="color: var(--color-text);" x-text="voiceStatusLabel()"></span></p>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="voiceEnabled" :disabled="busy">Freigeschaltet</label>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2 rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);"><span class="font-medium">API-Schlüssel:</span> <span x-text="voiceApiKeyLabel()"></span></div>
            <div>
                <label for="voice-base-url" class="block text-sm font-medium">Adresse des KI-Dienstes</label>
                <input id="voice-base-url" x-model="voiceBaseUrl" :disabled="busy" type="url" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="voice-transcribe-model" class="block text-sm font-medium">Transkriptionsmodell</label>
                <input id="voice-transcribe-model" x-model="voiceTranscribeModel" :disabled="busy" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="voice-language" class="block text-sm font-medium">Sprache</label>
                <input id="voice-language" x-model="voiceLanguage" :disabled="busy" type="text" maxlength="2" placeholder="de" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Leer lassen, damit das Modell die Sprache erkennt.</p>
            </div>
            <div>
                <label for="voice-postprocess-model" class="block text-sm font-medium">LLM für Nachbearbeitung und Logbuch</label>
                <input id="voice-postprocess-model" x-model="voicePostprocessModel" :disabled="busy || !voicePostprocessEnabled" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="flex items-center"><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="voicePostprocessEnabled" :disabled="busy">Transkript mit KI nachbearbeiten</label></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label for="voice-max-seconds" class="block text-sm font-medium">Max. Sekunden</label><input id="voice-max-seconds" x-model.number="voiceMaxSeconds" :disabled="busy" type="number" min="10" max="3600" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></div>
                <div><label for="voice-max-mb" class="block text-sm font-medium">Max. MB</label><input id="voice-max-mb" x-model.number="voiceMaxMb" :disabled="busy" type="number" min="1" max="25" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></div>
            </div>
            <div class="sm:col-span-2">
                <label for="voice-prompt" class="block text-sm font-medium">Anweisung für Sprachnotizen</label>
                <textarea id="voice-prompt" x-model="voicePrompt" :disabled="busy || !voicePostprocessEnabled" rows="10" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Erzeugt Titel, Notizbuchvorschlag und aufbereiteten Text.</p>
            </div>
            <div class="sm:col-span-2">
                <label for="voice-log-prompt" class="block text-sm font-medium">Anweisung für diktierte Logbuch-Einträge</label>
                <textarea id="voice-log-prompt" x-model="voiceLogPrompt" :disabled="busy" rows="10" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Ordnet Zeitpunkt und Werte den konfigurierten Logbuchspalten zu.</p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" class="btn btn-quiet" :disabled="busy" @click="resetVoicePrompt">Standardanweisungen</button><button type="button" class="btn btn-primary" :disabled="busy" @click="saveVoiceSettings">Speichern</button></div>
    </div>

    <div class="document-surface mb-8 p-5 sm:p-6">
        <div>
            <h2 class="text-lg font-semibold">NotesVoice</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Diktat per Rückseitentipp-Automation direkt in die Zwischenablage - ohne Notizbuch, ohne Überschrift, nur aufbereiteter Fließtext. Nutzt Freischaltung, API-Schlüssel, Transkriptionsmodell und Sprache aus den Sprachnotizen oben; Modell und Anweisung für die Nachbearbeitung sind hier eigene, weil hier kein Markdown und keine Notizbuchzuordnung gebraucht werden. Einrichtung auf dem iPhone: Einstellungen der App unter „Speech2Text“.</p>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="voice-quick-model" class="block text-sm font-medium">LLM für NotesVoice</label>
                <input id="voice-quick-model" x-model="voiceQuickModel" :disabled="busy || !voicePostprocessEnabled" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="sm:col-span-2">
                <label for="voice-quick-prompt" class="block text-sm font-medium">Anweisung für NotesVoice</label>
                <textarea id="voice-quick-prompt" x-model="voiceQuickPrompt" :disabled="busy || !voicePostprocessEnabled" rows="8" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Liefert Fließtext ohne Markdown-Syntax, damit er sich überall einfügen lässt.</p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" class="btn btn-quiet" :disabled="busy" @click="resetVoicePrompt">Standardanweisung</button><button type="button" class="btn btn-primary" :disabled="busy" @click="saveVoiceSettings">Speichern</button></div>
    </div>

    <div class="document-surface p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Textüberarbeitung im Notiz-Editor</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Korrigiert und strukturiert Notiztexte über den Zauberstab in der Editor-Toolbar. Aktuell: <span class="font-medium" style="color: var(--color-text);" x-text="noteAiStatusLabel()"></span></p>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="noteAiEnabled" :disabled="busy">Freigeschaltet</label>
        </div>

        <div class="mt-6 grid gap-5">
            <div class="rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                <span class="font-medium">API-Schlüssel:</span> wird gemeinsam mit den Sprachnotizen aus <code>OPENAI_KEY</code> verwendet.
            </div>
            <div>
                <label for="note-ai-model" class="block text-sm font-medium">LLM-Modell</label>
                <input id="note-ai-model" x-model="noteAiModel" :disabled="busy" type="text" placeholder="gpt-4o-mini" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="note-ai-prompt" class="block text-sm font-medium">Anweisung für die Textüberarbeitung</label>
                <textarea id="note-ai-prompt" x-model="noteAiPrompt" :disabled="busy" rows="14" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Das JSON-Antwortformat und die Regeln zum unveränderten Erhalt von Codeblöcken, Bildern, Tabellen, Checklisten und Links werden serverseitig immer zusätzlich erzwungen. Die Stilwahl „Normal“ oder „Einladend“ ergänzt diese Anweisung pro Anfrage.</p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" class="btn btn-quiet" :disabled="busy" @click="resetNoteAiPrompt">Standardanweisung</button>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="saveNoteAiSettings">Speichern</button>
        </div>
    </div>
</div>
