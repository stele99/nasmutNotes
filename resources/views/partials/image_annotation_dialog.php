<?php /*
    Vollbild-Editor für Bild-Annotationen (FR-ANNO-01/03/09). Alpine läuft im
    CSP-Build: hier stehen nur Methodennamen und einfache Eigenschaften -
    Werkzeugauswahl und Farbe laufen über data-Attribute, die aktive Klasse
    setzt annoSyncToolbar() imperativ (wie bei der Notiz-Werkzeugleiste).
*/ ?>
<div x-show="annoOpen" x-cloak class="fixed inset-0 z-[130] flex flex-col"
     style="background-color: rgb(0 0 0 / 0.92);"
     @keydown.escape.window="closeImageAnnotator"
     @keydown.window="annoKeydown"
     role="dialog" aria-modal="true" aria-labelledby="anno-dialog-title">

    <div class="flex items-center justify-between gap-3 px-4 py-3" style="background: var(--color-bg);">
        <h2 id="anno-dialog-title" class="text-base font-semibold">Bild beschriften</h2>
        <div class="flex items-center gap-2">
            <span class="hidden text-xs sm:inline" style="color: var(--color-text-muted);" x-text="annoCountLabel()"></span>
            <button type="button" @click="closeImageAnnotator" class="btn btn-quiet">Abbrechen</button>
            <button type="button" @click="applyImageAnnotations" class="btn btn-primary">Übernehmen</button>
        </div>
    </div>

    <div x-ref="annoToolbar" class="editor-toolbar flex flex-wrap items-center gap-1 border-b px-4 py-2"
         style="border-color: var(--color-border); background: var(--color-bg);">
        <button type="button" data-tool="select" @click="annoSelectTool" class="toolbar-button" title="Auswählen" aria-label="Auswählen" x-icon="square"></button>
        <button type="button" data-tool="pen" @click="annoSelectTool" class="toolbar-button" title="Freihand" aria-label="Freihand" x-icon="pencil"></button>
        <button type="button" data-tool="highlighter" @click="annoSelectTool" class="toolbar-button" title="Marker" aria-label="Marker" x-icon="highlighter"></button>
        <button type="button" data-tool="arrow" @click="annoSelectTool" class="toolbar-button" title="Pfeil" aria-label="Pfeil" x-icon="chevron-right"></button>
        <button type="button" data-tool="line" @click="annoSelectTool" class="toolbar-button" title="Linie" aria-label="Linie" x-icon="minus"></button>
        <button type="button" data-tool="rect" @click="annoSelectTool" class="toolbar-button" title="Rechteck" aria-label="Rechteck" x-icon="square"></button>
        <button type="button" data-tool="ellipse" @click="annoSelectTool" class="toolbar-button" title="Ellipse" aria-label="Ellipse" x-icon="circle"></button>
        <button type="button" data-tool="text" @click="annoSelectTool" class="toolbar-button" title="Text" aria-label="Text" x-icon="type"></button>
        <button type="button" data-tool="rules" @click="annoSelectTool" class="toolbar-button" title="Zeilenlinien" aria-label="Zeilenlinien" x-icon="list"></button>
        <button type="button" data-tool="marker" @click="annoSelectTool" class="toolbar-button" title="Nummer" aria-label="Nummer" x-icon="circle-dot"></button>
        <button type="button" data-tool="mask" @click="annoSelectTool" class="toolbar-button" title="Abdecken" aria-label="Abdecken" x-icon="eye-off"></button>
        <span class="toolbar-divider"></span>
        <?php foreach (['#e11d48', '#f97316', '#eab308', '#16a34a', '#2563eb', '#7c3aed', '#111827', '#ffffff'] as $color): ?>
            <button type="button" data-color="<?= $color ?>" @click="annoPickColor" class="anno-swatch"
                    style="background: <?= $color ?>;" title="Farbe <?= $color ?>" aria-label="Farbe <?= $color ?>"></button>
        <?php endforeach; ?>
        <input type="color" :value="annoColor" @input="annoColorInput" class="anno-swatch anno-swatch-picker" aria-label="Eigene Farbe">
        <span class="toolbar-divider"></span>
        <label class="sr-only" for="anno-width" x-text="annoWidthLabel()">Strichstärke</label>
        <input id="anno-width" type="range" min="1" max="80" step="1" :value="annoWidth" @input="annoWidthInput" class="w-24">
        <label class="sr-only" for="anno-opacity">Deckkraft</label>
        <input id="anno-opacity" type="range" min="0.1" max="1" step="0.05" :value="annoOpacity" @input="annoOpacityInput" class="w-20">
        <span class="toolbar-divider"></span>
        <button type="button" @click="annoUndoStep" class="toolbar-button" title="Rückgängig" aria-label="Rückgängig" x-icon="undo"></button>
        <button type="button" @click="annoRedoStep" class="toolbar-button" title="Wiederholen" aria-label="Wiederholen" x-icon="redo"></button>
        <button type="button" @click="annoDeleteSelected" class="toolbar-button toolbar-button-danger" title="Auswahl löschen" aria-label="Auswahl löschen" x-icon="trash"></button>
        <button type="button" @click="annoClearAll" class="toolbar-button toolbar-button-danger" title="Alle entfernen" aria-label="Alle entfernen" x-icon="x"></button>
    </div>

    <p x-show="annoNotice" x-cloak x-text="annoNotice" class="px-4 py-2 text-xs"
       style="background: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="status"></p>
    <p x-show="annoError" x-cloak x-text="annoError" class="px-4 py-2 text-xs"
       style="background: var(--color-bg); color: var(--color-danger);" role="alert"></p>

    <div class="anno-viewport flex flex-1 items-center justify-center overflow-auto p-4">
        <div x-ref="annoStage" class="anno-stage" :style="annoStageStyle()"
             @pointerdown="annoPointerDown" @pointermove="annoPointerMove"
             @pointerup="annoPointerUp" @pointercancel="annoPointerCancel"
             @dblclick="annoEditSelectedText">
            <img :src="annoSrc" :alt="annoAlt" class="anno-image" draggable="false">
            <span x-ref="annoLayer" class="anno-layer" aria-hidden="true"></span>
            <span x-ref="annoPreview" class="anno-layer" aria-hidden="true"></span>
            <span x-show="annoSelectionStyle" x-cloak class="anno-selection" :style="annoSelectionStyle">
                <span x-show="annoCanResizeSelection()" data-anno-handle="se" class="anno-handle"></span>
            </span>
        </div>
    </div>

    <div x-show="annoTextOpen" x-cloak class="fixed inset-0 z-[140] flex items-center justify-center p-5"
         style="background-color: rgb(0 0 0 / 0.45);" @click.self="annoCancelText"
         role="dialog" aria-modal="true" aria-labelledby="anno-text-title">
        <div class="w-full max-w-md rounded-xl border p-5"
             style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <h3 id="anno-text-title" class="text-lg font-semibold">Text auf dem Bild</h3>
            <textarea x-ref="annoTextInput" x-model="annoTextDraft" rows="4" maxlength="500"
                      class="mt-3 w-full rounded-md border px-3 py-2"
                      style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
            <p class="mt-1 text-xs" style="color: var(--color-text-muted);">
                Zeilenumbrüche werden übernommen; es wird nicht automatisch umbrochen.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="annoCancelText" class="btn btn-quiet">Abbrechen</button>
                <button type="button" @click="annoConfirmText" class="btn btn-primary">Einfügen</button>
            </div>
        </div>
    </div>
</div>
