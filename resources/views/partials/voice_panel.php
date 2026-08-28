<?php /* Zustand der Sprachaufnahme: läuft, pausiert, wird verarbeitet,
         gespeichert oder gescheitert. Wird in jede Komponente eingebunden,
         die den voiceRecorderMixin nutzt. In den Notiz-Kontexten liegt das
         Panel innerhalb des Aufnahme-Overlays (voice_template_picker.php),
         auf Aufgaben- und Logbuchseiten inline. Über $voicePanelBare lässt
         sich der eigene Rahmen abschalten, wenn die Dialoghülle ihn bereits
         mitbringt. */ ?>
<div x-show="isVoiceBusy() || voiceError || voiceNotice" x-cloak class="voice-panel<?php echo empty($voicePanelBare) ? ' mt-3' : ' voice-panel-bare'; ?>">
    <div x-show="voiceStatus === 'recording' || voiceStatus === 'paused'" x-cloak class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="voice-dot" :class="voiceStatus === 'paused' ? 'is-paused' : ''" aria-hidden="true"></span>
            <span class="font-medium" role="status" x-text="voiceStatusLabel()"></span>
            <span class="ml-auto text-xs" style="color: var(--color-text-muted);" x-text="voiceRemainingLabel()"></span>
        </div>
        <?php /* Die laufende Welle macht die Aufnahme auf einen Blick erkennbar;
                in der Pause friert sie zusammen mit der Zeit ein. */ ?>
        <div class="flex items-center gap-3">
            <span class="text-2xl font-semibold leading-none tabular-nums" x-text="voiceTimeLabel()"></span>
            <div class="voice-wave" :class="voiceStatus === 'paused' ? 'is-paused' : ''" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php /* Pause nur aus der laufenden Aufnahme, Fortsetzen nur aus der Pause - die Schalter zeigen den jeweiligen Weg. */ ?>
            <button type="button" x-show="voiceStatus === 'recording'" @click="pauseVoice" class="btn btn-quiet px-4 py-2 text-sm">Pause</button>
            <button type="button" x-show="voiceStatus === 'paused'" x-cloak @click="resumeVoice" class="btn btn-quiet px-4 py-2 text-sm"><span x-icon="play"></span>Fortsetzen</button>
            <button type="button" @click="stopVoice" class="btn btn-primary px-4 py-2 text-sm">
                <span x-icon="square"></span>Fertig
            </button>
            <button type="button" @click="cancelVoice" class="btn btn-quiet px-4 py-2 text-sm">Verwerfen</button>
        </div>
    </div>
    <p x-show="voiceStatus === 'starting'" x-cloak class="text-sm" style="color: var(--color-text-muted);" role="status">Mikrofon wird vorbereitet…</p>
    <p x-show="voiceStatus === 'processing'" x-cloak class="text-sm" style="color: var(--color-text-muted);">
        Die Aufnahme wird transkribiert und aufbereitet…
    </p>
    <p x-show="voiceStatus === 'saving'" x-cloak class="text-sm" style="color: var(--color-text-muted);" role="status">Notiz wird gespeichert…</p>
    <div x-show="voiceError" x-cloak class="flex items-start justify-between gap-2 text-sm" style="color: var(--color-danger);" role="alert">
        <span class="min-w-0" x-text="voiceError"></span>
        <button type="button" @click="dismissVoiceError" class="icon-action icon-action-danger shrink-0" aria-label="Fehlermeldung schließen" x-icon="x"></button>
    </div>
    <p x-show="voiceNotice && !isVoiceBusy()" x-cloak x-text="voiceNotice" class="text-sm" style="color: var(--color-text-muted);" role="status"></p>
</div>
