<?php /* Ergebnisse der Umkreissuche (FR-NOTE-27): eine Zeile je Seite. Ein
         Logbuch mit mehreren passenden Einträgen erscheint trotzdem nur
         einmal - mit der Entfernung zum nächstgelegenen Treffer. */ ?>
<div class="flex items-center justify-between px-1 py-2">
    <span class="text-sm" style="color: var(--color-text-muted);" x-text="nearbyResultsLabel()"></span>
    <button type="button" @click="clearNearby" class="icon-action" aria-label="Umkreissuche verlassen" title="Umkreissuche verlassen" x-icon="x"></button>
</div>
<template x-for="item in nearbyResults" :key="nearbyResultKey(item)">
    <div class="page-card cursor-pointer px-4 py-3" @click="openNearbyResult(item)" @keydown.enter.prevent="openNearbyResult(item)" role="link" tabindex="0">
        <div class="flex items-start gap-2">
            <?php /* x-icon liest seinen Ausdruck als festen Symbolnamen, nicht als
                     JS-Ausdruck - eine je Zustand sichtbare Alternative statt eines
                     dynamisch gebundenen Namens (siehe sidebar.php ebenso). */ ?>
            <span x-show="item.page_type === 'log'" class="mt-0.5 shrink-0" style="color: var(--color-text-muted);" x-icon="scroll-text"></span>
            <span x-show="item.page_type === 'task'" class="mt-0.5 shrink-0" style="color: var(--color-text-muted);" x-icon="list-todo"></span>
            <span class="min-w-0 flex-1 break-words leading-snug" x-text="item.title"></span>
        </div>
        <p class="ml-6 mt-1 truncate text-xs" style="color: var(--color-text-muted);" x-text="nearbyResultSubtitle(item)"></p>
    </div>
</template>
<p x-show="nearbyResults.length === 0" class="px-4 py-6 text-sm" style="color: var(--color-text-muted);">Keine Seiten im Umkreis gefunden.</p>
