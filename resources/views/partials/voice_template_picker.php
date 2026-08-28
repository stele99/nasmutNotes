<?php /* Vorlagenauswahl vor dem Diktat einer Notiz (FR-VOICE): Vor jeder
         Aufnahme muss der Nutzer eine Vorlage wählen, die bestimmt, wie das
         Diktat aufbereitet wird. Wird zusammen mit voice_panel.php in jede
         Komponente eingebunden, die voiceTemplateMixin() nutzt (Übersicht,
         Seitenleiste, Notizseite). x-teleport wie beim Einstellungsdialog,
         damit `position: fixed` zentriert über dem ganzen Bildschirm sitzt. */ ?>
<template x-teleport="body">
<div x-show="voiceTemplatePickerOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-5" style="background-color: rgb(0 0 0 / 0.4);" @click.self="cancelVoiceTemplatePicker" @keydown.escape.window="cancelVoiceTemplatePicker">
    <div class="flex w-full max-w-md flex-col overflow-hidden rounded-xl border" role="dialog" aria-modal="true" aria-labelledby="voice-template-picker-title" style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
        <header class="flex items-center justify-between gap-3 border-b px-5 py-4" style="border-color: var(--color-border);">
            <h2 id="voice-template-picker-title" class="font-semibold">Vorlage für das Diktat</h2>
            <button type="button" @click="cancelVoiceTemplatePicker" class="icon-action" aria-label="Abbrechen" x-icon="x"></button>
        </header>
        <div class="max-h-[60vh] space-y-1 overflow-y-auto p-3">
            <template x-for="template in voiceTemplates" :key="template.id">
                <button type="button" @click="confirmVoiceTemplate(template.id)" class="flex w-full items-start gap-2 rounded-md p-3 text-left hover:bg-black/5 dark:hover:bg-white/10" :style="voiceTemplateId === template.id ? 'border: 1px solid var(--color-accent); background: var(--color-bg-subtle);' : 'border: 1px solid var(--color-border);'">
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
            <button type="button" @click="confirmVoiceTemplate(voiceTemplateId)" :disabled="voiceTemplateId === null" class="btn btn-primary">Aufnahme starten</button>
        </div>
    </div>
</div>
</template>
