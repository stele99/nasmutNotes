<?php /* Globale Toast-Benachrichtigungen (NFR-UI-07, NFR-A11Y-03): eine
         einzelne Live-Region statt lokaler Erfolgsmeldungen je Dialog. */ ?>
<div
    x-data="toast"
    class="toast-host fixed inset-x-0 bottom-4 z-[200] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end"
    aria-live="polite"
    aria-atomic="false"
>
    <template x-for="item in items" :key="item.id">
        <div class="toast-item" :class="item.variant === 'error' ? 'is-error' : ''">
            <span x-text="item.message"></span>
            <button type="button" @click="dismiss(item.id)" class="toast-dismiss" aria-label="Meldung schließen" x-icon="x"></button>
        </div>
    </template>
</div>
