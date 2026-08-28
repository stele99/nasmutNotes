<div class="mx-auto max-w-6xl px-5 py-10 sm:px-10 sm:py-14" x-data="adminDashboard">
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

    <?php /* Linke Sprungleiste: Direkt zu jedem Bereich springen. Auf
             schmalen Bildschirmen eine horizontale, scrollbarer Streifen. */ ?>
    <div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-8">
        <nav class="admin-section-nav mb-8 flex gap-2 overflow-x-auto pb-2 lg:sticky lg:top-6 lg:mb-0 lg:h-fit lg:flex-col lg:overflow-visible lg:pb-0" aria-label="Bereiche der KI-Einstellungen">
            <a href="#ki-gemeinsam" class="settings-nav-button shrink-0"><span x-icon="cpu"></span><span>Gemeinsames</span></a>
            <a href="#ki-sprachnotizen" class="settings-nav-button shrink-0"><span x-icon="mic"></span><span>Sprachnotizen</span></a>
            <a href="#ki-notesvoice" class="settings-nav-button shrink-0"><span x-icon="zap"></span><span>NotesVoice</span></a>
            <a href="#ki-vorlagen" class="settings-nav-button shrink-0"><span x-icon="file-text"></span><span>Vorlagen</span></a>
            <a href="#ki-notiz-ki" class="settings-nav-button shrink-0"><span x-icon="wand-sparkles"></span><span>Notiz-KI</span></a>
            <a href="#ki-assistant" class="settings-nav-button shrink-0"><span x-icon="laptop"></span><span>Desktop-Assistant</span></a>
            <a href="#ki-kosten" class="settings-nav-button shrink-0"><span x-icon="wallet"></span><span>Modellkosten</span></a>
            <a href="#ki-verbrauch" class="settings-nav-button shrink-0"><span x-icon="chart-column"></span><span>Verbrauch</span></a>
        </nav>

        <div class="min-w-0">
        <div id="ki-gemeinsam" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Gemeinsame KI-Einstellungen</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Ein LLM und eine Dienst-Adresse für alle Bereiche: Nachbearbeitung der Sprachnotizen, Logbuch- und Aufgaben-Diktat, NotesVoice, Notiz-KI und Desktop-Assistant. Was die Bereiche selbst beitragen, sind Anweisung und Reasoning-Aufwand.</p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2 rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                    <span class="font-medium">API-Schlüssel:</span> <span x-text="voiceApiKeyLabel()"></span>
                </div>
                <div>
                    <label for="ai-default-model" class="block text-sm font-medium">KI-Modell (Standard für alle Bereiche)</label>
                    <input id="ai-default-model" x-model="aiDefaultModel" :disabled="busy" type="text" placeholder="gpt-4o-mini" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                </div>
                <div>
                    <label for="ai-default-reasoning" class="block text-sm font-medium">Reasoning-Aufwand (Vorgabe)</label>
                    <select id="ai-default-reasoning" x-model="aiDefaultReasoning" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="">Nicht senden</option>
                        <option value="minimal">minimal</option>
                        <option value="low">low</option>
                        <option value="medium">medium</option>
                        <option value="high">high</option>
                        <option value="xhigh">xhigh</option>
                        <option value="none">none</option>
                    </select>
                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Nur für Reasoning-Modelle setzen (o-Serie, gpt-5 …); bei anderen Modellen leer lassen. Bereiche können abweichen.</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="voice-base-url" class="block text-sm font-medium">Adresse des KI-Dienstes</label>
                    <input id="voice-base-url" x-model="voiceBaseUrl" :disabled="busy" type="url" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                </div>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" class="btn btn-primary" :disabled="busy" @click="saveAiDefaults">Speichern</button></div>
        </div>

        <div id="ki-sprachnotizen" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Sprachnotizen und Logbuch-Diktat</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Transkription, KI-Nachbearbeitung, Notizbuchvorschläge und die Zuordnung diktierter Logbuchwerte. Aktuell: <span class="font-medium" style="color: var(--color-text);" x-text="voiceStatusLabel()"></span></p>
                </div>
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="voiceEnabled" :disabled="busy">Freigeschaltet</label>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="voice-transcribe-model" class="block text-sm font-medium">Transkriptionsmodell</label>
                    <input id="voice-transcribe-model" x-model="voiceTranscribeModel" :disabled="busy" type="text" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Eigenes Audio-Modell - das gemeinsame KI-Modell gilt hier nicht.</p>
                </div>
                <div>
                    <label for="voice-language" class="block text-sm font-medium">Sprache</label>
                    <input id="voice-language" x-model="voiceLanguage" :disabled="busy" type="text" maxlength="2" placeholder="de" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Leer lassen, damit das Modell die Sprache erkennt.</p>
                </div>
                <div>
                    <label for="voice-postprocess-reasoning" class="block text-sm font-medium">Reasoning-Aufwand</label>
                    <select id="voice-postprocess-reasoning" x-model="voicePostprocessReasoning" :disabled="busy || !voicePostprocessEnabled" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="">Globale Vorgabe</option>
                        <option value="minimal">minimal</option>
                        <option value="low">low</option>
                        <option value="medium">medium</option>
                        <option value="high">high</option>
                        <option value="xhigh">xhigh</option>
                        <option value="none">none</option>
                    </select>
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

        <div id="ki-notesvoice" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
            <div>
                <h2 class="text-lg font-semibold">NotesVoice</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Diktat per Rückseitentipp-Automation direkt in die Zwischenablage - ohne Notizbuch, ohne Überschrift, nur aufbereiteter Fließtext. Nutzt Freischaltung, API-Schlüssel, Transkriptionsmodell und Sprache aus den Sprachnotizen oben. Einrichtung auf dem iPhone: Einstellungen der App unter „Speech2Text“.</p>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="voice-quick-reasoning" class="block text-sm font-medium">Reasoning-Aufwand</label>
                    <select id="voice-quick-reasoning" x-model="voiceQuickReasoning" :disabled="busy || !voicePostprocessEnabled" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="">Globale Vorgabe</option>
                        <option value="minimal">minimal</option>
                        <option value="low">low</option>
                        <option value="medium">medium</option>
                        <option value="high">high</option>
                        <option value="xhigh">xhigh</option>
                        <option value="none">none</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="voice-quick-prompt" class="block text-sm font-medium">Anweisung für NotesVoice</label>
                    <textarea id="voice-quick-prompt" x-model="voiceQuickPrompt" :disabled="busy || !voicePostprocessEnabled" rows="8" class="mt-2 w-full rounded-md border px-3 py-2 font-mono text-xs" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                    <p class="mt-1 text-xs" style="color: var(--color-text-muted);">Liefert Fließtext ohne Markdown-Syntax, damit er sich überall einfügen lässt.</p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" class="btn btn-primary" :disabled="busy" @click="saveQuickSettings">Speichern</button></div>
        </div>

        <div id="ki-vorlagen" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
            <div>
                <h2 class="text-lg font-semibold">Vorlagen</h2>
                <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Globale Diktier-Vorlagen: für alle Nutzer wählbar, bevor sie eine Notiz per Diktat aufnehmen. Jeder Nutzer kann zusätzlich eigene Vorlagen unter Einstellungen › Speech2Text › Vorlagen anlegen.</p>
            </div>

            <div x-show="voiceTemplates.length > 0" x-cloak class="mt-5 space-y-2">
                <template x-for="template in voiceTemplates" :key="template.id">
                    <div class="rounded-lg border p-3" style="border-color: var(--color-border);">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-medium" x-text="template.name"></span>
                            <span class="flex shrink-0 gap-2">
                                <button type="button" @click="startEditVoiceTemplate(template)" class="text-xs font-medium" style="color: var(--color-text-muted);">Bearbeiten</button>
                                <button type="button" @click="deleteVoiceTemplate(template)" class="text-xs font-medium" style="color: var(--color-danger);">Entfernen</button>
                            </span>
                        </div>
                        <p class="mt-1 whitespace-pre-line text-xs" style="color: var(--color-text-muted);" x-text="template.instruction"></p>
                    </div>
                </template>
            </div>
            <p x-show="voiceTemplates.length === 0" x-cloak class="mt-4 text-sm" style="color: var(--color-text-muted);">Noch keine globale Vorlage angelegt.</p>

            <form @submit.prevent="saveVoiceTemplate" class="mt-5 space-y-3 rounded-lg border p-3" style="border-color: var(--color-border);">
                <p class="text-xs font-semibold" x-text="editingVoiceTemplateId ? 'Vorlage bearbeiten' : 'Neue Vorlage'"></p>
                <div>
                    <label for="voice-template-name" class="block text-sm font-medium">Name</label>
                    <input id="voice-template-name" type="text" maxlength="80" x-model="voiceTemplateName" placeholder="z. B. Angebot mit Positionen" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                </div>
                <div>
                    <label for="voice-template-instruction" class="block text-sm font-medium">Anweisung</label>
                    <textarea id="voice-template-instruction" x-model="voiceTemplateInstruction" placeholder="Wie soll das Diktat aufbereitet werden?" rows="5" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                </div>
                <div>
                    <label for="voice-template-vocabulary" class="block text-sm font-medium">Vokabular (optional)</label>
                    <textarea id="voice-template-vocabulary" x-model="voiceTemplateVocabulary" placeholder="Fachbegriffe/Schreibweisen (optional)" rows="2" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button x-show="editingVoiceTemplateId" x-cloak type="button" @click="cancelEditVoiceTemplate" class="btn btn-quiet" :disabled="busy">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" :disabled="busy" x-text="editingVoiceTemplateId ? 'Speichern' : 'Anlegen'"></button>
                </div>
            </form>
        </div>

        <div id="ki-notiz-ki" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
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
                    <label for="note-ai-reasoning" class="block text-sm font-medium">Reasoning-Aufwand</label>
                    <select id="note-ai-reasoning" x-model="noteAiReasoning" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="">Globale Vorgabe</option>
                        <option value="minimal">minimal</option>
                        <option value="low">low</option>
                        <option value="medium">medium</option>
                        <option value="high">high</option>
                        <option value="xhigh">xhigh</option>
                        <option value="none">none</option>
                    </select>
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

        <div id="ki-assistant" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Desktop-Assistant</h2>
                    <p class="mt-1 text-sm" style="color: var(--color-text-muted);">Die Desktop-App sendet ihre KI-Anfragen an diesen Server und spricht Standard-OpenAI. Aktuell: <span class="font-medium" style="color: var(--color-text);" x-text="assistantStatusLabel()"></span></p>
                </div>
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" x-model="assistantEnabled" :disabled="busy">Freigeschaltet</label>
            </div>

            <div class="mt-6 grid gap-5">
                <div class="rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                    <span class="font-medium">Modell, Adresse und Schlüssel:</span> kommen aus den gemeinsamen KI-Einstellungen oben. <span x-text="assistantApiKeyLabel()"></span>
                </div>
                <div>
                    <label for="assistant-reasoning" class="block text-sm font-medium">Reasoning-Aufwand</label>
                    <select id="assistant-reasoning" x-model="assistantReasoning" :disabled="busy" class="mt-2 w-full rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                        <option value="">Globale Vorgabe</option>
                        <option value="minimal">minimal</option>
                        <option value="low">low</option>
                        <option value="medium">medium</option>
                        <option value="high">high</option>
                        <option value="xhigh">xhigh</option>
                        <option value="none">none</option>
                    </select>
                </div>
                <div class="rounded-md border p-3 text-sm" style="border-color: var(--color-border); background: var(--color-bg-subtle);">
                    <span class="font-medium">Transkription:</span> nutzt unverändert Modell, Sprache und Adresse aus den Sprachnotizen oben. Die Verbindung der Desktop-App läuft über einen Paarungsgcode; verbundene Geräte stehen in den Nutzereinstellungen unter „Verbundene Clients“.
                </div>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" class="btn btn-primary" :disabled="busy" @click="saveAssistantSettings">Speichern</button>
            </div>
        </div>

        <div id="ki-kosten" class="document-surface mb-8 scroll-mt-6 p-5 sm:p-6">
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

        <div id="ki-verbrauch" class="document-surface scroll-mt-6 p-5 sm:p-6">
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
    </div>
</div>
