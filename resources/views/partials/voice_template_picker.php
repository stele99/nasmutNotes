<?php /* Modale Hülle für den gesamten Notiz-Voice-Fluss (FR-VOICE-01..04):
         Vorlagenauswahl, Aufnahme mit Pause und Fortsetzen, Verarbeitung,
         Speicherung und Fehler bleiben in derselben teleportierten Hülle,
         damit Backdrop und Popup zwischen den Phasen nicht sichtbar
         verschwinden. Hinweis und Erfolg liegen außerhalb des Dialogs
         (voice_status.php), damit er nach dem Speichern schließen kann.
         Wird in jede Komponente eingebunden, die voiceTemplateMixin() nutzt
         (Übersicht, Seitenleiste, Notizseite). x-teleport wie beim
         Einstellungsdialog, damit `position: fixed` zentriert über dem
         ganzen Bildschirm sitzt.

         Der Dialog wird auf einer Seite mehrfach eingebunden (Seitenleiste
         und Hauptbereich haben je eine eigene Komponente), deshalb beschriftet
         der dynamische aria-label den Dialog statt einer ID - doppelte IDs
         wären ungültig. */ ?>
<template x-teleport="body">
<div x-show="isVoiceDialogOpen()" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-3 sm:p-5" style="background-color: rgb(0 0 0 / 0.4);" @click.self="closeVoiceDialog" @keydown.escape.window="closeVoiceDialog">
    <div class="relative flex w-full max-w-md flex-col overflow-hidden rounded-xl border" role="dialog" aria-modal="true" :aria-label="voiceDialogTitle()" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <?php /* Roter Saum, solange wirklich aufgenommen wird - das Overlay
                liest sich damit sofort als "Recording". */ ?>
        <div x-show="voiceStatus === 'recording'" x-cloak class="pointer-events-none absolute inset-0 rounded-xl" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-danger) 45%, transparent);" aria-hidden="true"></div>
        <header class="flex items-center justify-between gap-3 border-b px-5 py-4" style="border-color: var(--color-border);">
            <h2 class="font-semibold" x-text="voiceDialogTitle()"></h2>
            <?php /* Läuft ein Vorgang, gibt es keinen Schließen-Schalter:
                     Verwerfen (Aufnahme und Pause) bzw. nichts (Verarbeitung
                     und Speicherung) sind dann die einzigen Wege. */ ?>
            <button type="button" x-show="!isVoiceBusy()" x-cloak @click="closeVoiceDialog" class="icon-action" aria-label="Dialog schließen" x-icon="x"></button>
        </header>
        <div x-show="voiceTemplatePickerOpen" x-cloak>
            <div class="max-h-[60vh] space-y-1 overflow-y-auto p-3">
                <template x-for="template in voiceTemplates" :key="template.id">
                    <button type="button" @click="selectVoiceTemplate(template.id)" :aria-pressed="voiceTemplateId === template.id" class="flex w-full items-start gap-2 rounded-md p-3 text-left hover:bg-black/5 dark:hover:bg-white/10" :style="voiceTemplateId === template.id ? 'border: 1px solid var(--color-accent); background: var(--color-bg-subtle);' : 'border: 1px solid var(--color-border);'">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium" x-text="template.name"></span>
                            <span class="mt-0.5 block text-xs" style="color: var(--color-text-muted);" x-text="template.scope === 'global' ? 'Globale Vorlage' : 'Eigene Vorlage'"></span>
                        </span>
                        <span x-show="voiceTemplateId === template.id" class="shrink-0" x-icon="check"></span>
                    </button>
                </template>
            </div>
            <div class="flex justify-end gap-2 border-t px-5 py-4" style="border-color: var(--color-border);">
                <button type="button" @click="cancelVoiceTemplatePicker" class="btn btn-quiet">Abbrechen</button>
                <button type="button" x-ref="voiceTemplateConfirm" @click="confirmVoiceTemplate" :disabled="voiceTemplateId === null" class="btn btn-primary">Aufnahme starten</button>
            </div>
        </div>
        <?php /* Aufnahme, Pause, Verarbeitung, Speicherung und Fehler - das
                 Panel ohne eigenen Rahmen, der Dialog bringt ihn mit. */ ?>
        <div x-show="!voiceTemplatePickerOpen" x-cloak class="px-5 py-5">
            <?php $voicePanelBare = true; include __DIR__ . '/voice_panel.php'; unset($voicePanelBare); ?>
        </div>
    </div>
</div>
</template>
