<?php /* Sync-Hinweis über dem Inhalt (NFR-UI-26): nur ohne Verbindung oder bei
         hängenden Änderungen, und nur unter `xl` - darüber steht die
         Statuszeile der Notizbuchleiste ohnehin im Blick. `$syncStatusClass`
         trägt Abstand und Form der jeweiligen Seite. Die Anzeige setzen
         `flex`/`xl:hidden` am Element, nicht app.css - ungeschichtetes CSS
         überstimmte sonst die Utility (siehe NFR-UI-18). */ ?>
<div x-data="syncStatus" x-show="isVisible()" x-cloak class="sync-status flex items-center gap-2 xl:hidden <?= e($syncStatusClass ?? '') ?>" :class="toneClass()" role="status">
    <span class="sync-status-dot" aria-hidden="true"></span>
    <span class="min-w-0" x-text="text()"></span>
</div>
