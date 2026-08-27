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

    <div class="document-surface mb-8 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Desktop-Assistant</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Die Desktop-App sendet ihre KI-Anfragen an diesen Server und spricht Standard-OpenAI. Aktuell: <span class="font-medium" style="color: var(--color-text);" x-text="assistantStatusLabel()"></span></p>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="assistantEnabled" :disabled="busy">Freigeschaltet</label>
        </div>

        <div class="mt-6 grid gap-5">
            <div class="rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                <span class="font-medium">API-Schlüssel:</span> wird gemeinsam mit den Sprachnotizen aus <code>OPENAI_KEY</code> verwendet. <span x-text="assistantApiKeyLabel()"></span>
            </div>
            <div>
                <label for="assistant-chat-model" class="block text-sm font-medium">LLM-Modell für die Desktop-App</label>
                <input id="assistant-chat-model" x-model="assistantChatModel" :disabled="busy" type="text" placeholder="Standard der KI-Funktionen" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Diese Angabe überschreibt jedes vom Client gesendete Modell. Leer = das Standard-LLM der KI-Funktionen.</p>
            </div>
            <div>
                <label for="assistant-chat-base-url" class="block text-sm font-medium">Adresse des KI-Dienstes</label>
                <input id="assistant-chat-base-url" x-model="assistantChatBaseUrl" :disabled="busy" type="url" placeholder="Standard der KI-Funktionen" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Leer = dieselbe Adresse wie bei den Sprachnotizen. Der Dienst muss denselben API-Schlüssel akzeptieren.</p>
            </div>
            <div class="rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                <span class="font-medium">Transkription:</span> nutzt unverändert Modell, Sprache und Adresse aus den Sprachnotizen oben. Die Verbindung der Desktop-App läuft über einen Paarungsgcode; verbundenen Geräten stehen denselben Nutzerwegen unter „Verbundene Clients“.
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" class="btn btn-primary" :disabled="busy" @click="saveAssistantSettings">Speichern</button>
        </div>
    </div>

    <div class="document-surface mb-8 p-5 sm:p-6">
        <div>
            <h2 class="text-lg font-semibold">Modellkosten</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Euro je 1 Mio. Tokens, Input und Output getrennt. Die Verrechnung geschieht zur Anzeigezeit - Korrekturen wirken rückwirkend. Modelle ohne Eintrag zählen mit, kosten aber nichts.</p>
        </div>

        <div x-show="aiCosts.length > 0" x-cloak class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" style="border-color: var(--color-border);">
                        <th class="py-2 pr-3 font-medium">Modell</th>
                        <th class="py-2 pr-3 font-medium">Input €/1M</th>
                        <th class="py-2 pr-3 font-medium">Output €/1M</th>
                        <th class="py-2 pr-3 font-medium">Währung</th>
                        <th class="py-2 font-medium"><span class="sr-only">Aktion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="cost in aiCosts" :key="cost.model">
                        <tr class="border-b" style="border-color: var(--color-border);">
                            <td class="py-2 pr-3 font-medium" x-text="cost.model"></td>
                            <td class="py-2 pr-3" x-text="formatCost(cost.input_per_1m, cost.currency)"></td>
                            <td class="py-2 pr-3" x-text="formatCost(cost.output_per_1m, cost.currency)"></td>
                            <td class="py-2 pr-3" x-text="cost.currency"></td>
                            <td class="py-2 text-right"><button type="button" @click="deleteCost(cost)" class="font-medium" style="color: var(--color-danger);">Entfernen</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p x-show="aiCosts.length === 0" x-cloak class="mt-4 text-sm" style="color: var(--color-text-muted);">Noch kein Modell eingetragen.</p>

        <form @submit.prevent="saveCost" class="mt-5 grid gap-3 sm:grid-cols-5">
            <div class="sm:col-span-2">
                <label for="cost-model" class="block text-sm font-medium">Modell</label>
                <input id="cost-model" x-model="costModel" :disabled="busy" type="text" placeholder="gpt-4o-mini" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="cost-input" class="block text-sm font-medium">Input €/1M</label>
                <input id="cost-input" x-model="costInput" :disabled="busy" type="number" step="0.0001" min="0" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div>
                <label for="cost-output" class="block text-sm font-medium">Output €/1M</label>
                <input id="cost-output" x-model="costOutput" :disabled="busy" type="number" step="0.0001" min="0" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
            </div>
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label for="cost-currency" class="block text-sm font-medium">Währung</label>
                    <input id="cost-currency" x-model="costCurrency" :disabled="busy" type="text" maxlength="3" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                </div>
                <button type="submit" class="btn btn-primary shrink-0" :disabled="busy">Eintragen</button>
            </div>
        </form>
    </div>

    <div class="document-surface p-5 sm:p-6">
        <div>
            <h2 class="text-lg font-semibold">Verbrauch &amp; Kosten</h2>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Tokens und verursachte Kosten je Nutzer - rollierend letzte 30 Tage und gesamt. Ohne gepflegte Preise bleiben die Kosten leer.</p>
        </div>

        <div x-show="aiUsage !== null && (aiUsage.users || []).length > 0" x-cloak class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" style="border-color: var(--color-border);">
                        <th class="py-2 pr-3 font-medium">Nutzer</th>
                        <th class="py-2 pr-3 font-medium">Tokens · 30 Tage</th>
                        <th class="py-2 pr-3 font-medium">Tokens · gesamt</th>
                        <th class="py-2 pr-3 font-medium">Kosten · 30 Tage</th>
                        <th class="py-2 font-medium">Kosten · gesamt</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="user in aiUsage?.users || []" :key="user.user_id">
                        <tr class="border-b" style="border-color: var(--color-border);">
                            <td class="py-2 pr-3"><span class="font-medium" x-text="user.name || user.email"></span></td>
                            <td class="py-2 pr-3" x-text="formatTokens(user.tokens_30d)"></td>
                            <td class="py-2 pr-3" x-text="formatTokens(user.tokens_total)"></td>
                            <td class="py-2 pr-3" x-text="formatCost(user.cost_30d, user.currency)"></td>
                            <td class="py-2" x-text="formatCost(user.cost_total, user.currency)"></td>
                        </tr>
                    </template>
                    <tr class="border-b font-semibold" style="border-color: var(--color-border);">
                        <td class="py-2 pr-3">Gesamt</td>
                        <td class="py-2 pr-3" x-text="formatTokens(aiUsage?.totals?.tokens_30d)"></td>
                        <td class="py-2 pr-3" x-text="formatTokens(aiUsage?.totals?.tokens_total)"></td>
                        <td class="py-2 pr-3" x-text="formatCost(aiUsage?.totals?.cost_30d, aiUsage?.totals?.currency)"></td>
                        <td class="py-2" x-text="formatCost(aiUsage?.totals?.cost_total, aiUsage?.totals?.currency)"></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p x-show="aiUsage !== null && (aiUsage.users || []).length === 0" x-cloak class="mt-4 text-sm" style="color: var(--color-text-muted);">Es ist noch kein KI-Verbrauch entstanden.</p>
    </div>
</div>
