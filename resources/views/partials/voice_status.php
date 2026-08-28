<?php /* Hinweis und Fehler der Sprachaufnahme außerhalb des Aufnahme-Overlays:
         Das Popup schließt sich nach dem Speichern selbst, der Erfolgshinweis
         (etwa aus pageList.showVoiceNotice()) soll aber weiter sichtbar
         bleiben. Aufnahme und Verarbeitung zeigt das Overlay selbst
         (voice_template_picker.php). */ ?>
<div x-show="voiceError || voiceNotice" x-cloak class="mt-3 max-w-2xl space-y-1">
    <p x-show="voiceError" x-cloak x-text="voiceError" class="text-sm" style="color: var(--color-danger);" role="alert"></p>
    <p x-show="voiceNotice" x-cloak x-text="voiceNotice" class="text-sm" style="color: var(--color-text-muted);" role="status"></p>
</div>
