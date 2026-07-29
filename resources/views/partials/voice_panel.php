<?php /* Zustand der Sprachaufnahme: läuft, wird verarbeitet, gescheitert.
         Wird in jede Komponente eingebunden, die den voiceRecorderMixin nutzt
         (Übersicht, Seitenleiste, Notizseite). */ ?>
<div x-show="isVoiceBusy() || voiceError || voiceNotice" x-cloak class="voice-panel mt-3">
    <div x-show="voiceStatus === 'recording'" class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="voice-dot" aria-hidden="true"></span>
        <span class="font-medium tabular-nums" x-text="voiceTimeLabel()"></span>
        <span class="text-xs" style="color: var(--color-text-muted);" x-text="voiceRemainingLabel()"></span>
        <div class="ml-auto flex gap-2">
            <button type="button" @click="stopVoice" class="btn btn-primary px-3 py-1.5 text-sm">
                <span x-icon="square"></span>Fertig
            </button>
            <button type="button" @click="cancelVoice" class="btn btn-quiet px-3 py-1.5 text-sm">Verwerfen</button>
        </div>
    </div>
    <p x-show="voiceStatus === 'processing'" x-cloak class="text-sm" style="color: var(--color-text-muted);">
        Die Aufnahme wird transkribiert und aufbereitet…
    </p>
    <p x-show="voiceError" x-cloak x-text="voiceError" class="text-sm" style="color: var(--color-danger);" role="alert"></p>
    <p x-show="voiceNotice && !isVoiceBusy()" x-cloak x-text="voiceNotice" class="text-sm" style="color: var(--color-text-muted);" role="status"></p>
</div>
