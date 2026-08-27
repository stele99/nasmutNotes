<?php /* Bestätigungsseite der Desktop-Assistant-Paarung: geöffnet vom
         Client im Browser des Nutzers, nur mit kurzer Code-Übergabe. */ ?>
<div class="flex min-h-screen items-center justify-center px-5" style="background: var(--color-bg-subtle);"
     x-data="assistantPair" x-init="init"
     data-pair-code="<?= e($pairCode ?? '') ?>"
     data-pair-label="<?= e($pairLabel ?? '') ?>"
     data-pair-platform="<?= e($pairPlatform ?? '') ?>">
    <div class="document-surface w-full max-w-md p-8 sm:p-10">
        <p class="text-sm font-medium" style="color: var(--color-accent);">Verbindung anfordern</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Desktop-Assistant</h1>

        <div x-show="status === 'missing'" x-cloak class="mt-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);">
            <p>Der Verbindungsgcode fehlt. Starte den Desktop-Assistenten neu und öffne den Link erneut.</p>
        </div>

        <div x-show="status !== 'missing'" x-cloak>
            <div x-show="status === 'unknown'" class="mt-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);">
                <p>Dieser Verbindungsgcode ist unbekannt oder abgelaufen. Starte den Desktop-Assistenten neu und öffne den Link erneut.</p>
            </div>

            <template x-if="status !== 'unknown'">
                <div>
                    <p class="mt-4 text-base" style="color: var(--color-text-muted);">
                        Ein Gerät möchte sich mit deinem Konto verbinden und die KI-Funktionen nutzen:
                    </p>
                    <div class="mt-4 rounded-lg p-4" style="background: var(--color-bg-subtle);">
                        <p class="text-base font-medium" x-text="label"></p>
                        <p x-show="platform !== ''" class="mt-1 text-sm" style="color: var(--color-text-muted);" x-text="platform"></p>
                        <p class="mt-2 text-sm" style="color: var(--color-text-muted);">Code: <span class="font-mono" x-text="code"></span></p>
                    </div>

                    <div x-show="status === 'ready'" x-cloak class="mt-6">
                        <button type="button" class="btn btn-primary w-full" :disabled="busy" @click="approve"
                                x-text="busy ? 'Verbinde…' : 'Verbinden'"></button>
                        <p class="mt-3 text-xs" style="color: var(--color-text-muted);">Nach dem Verbinden kannst du das Fenster schließen. Das Gerät erscheint unter Einstellungen &rarr; Verbundene Clients und ist jederzeit trennbar.</p>
                    </div>

                    <div x-show="status === 'approved'" x-cloak class="mt-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-success) 14%, transparent);">
                        <p class="font-medium">Verbunden.</p>
                        <p class="mt-1" style="color: var(--color-text-muted);">Der Desktop-Assistent hat seine Verbindung aufgebaut. Du kannst dieses Fenster jetzt schließen.</p>
                    </div>

                    <div x-show="status === 'invalid'" x-cloak class="mt-6 rounded-lg p-4 text-sm" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);">
                        <p x-text="error !== '' ? error : 'Der Code ist abgelaufen oder wurde bereits bestätigt.'"></p>
                        <p class="mt-1" style="color: var(--color-text-muted);">Starte den Desktop-Assistenten neu, um einen neuen Code zu erzeugen.</p>
                    </div>

                    <p x-show="error !== '' && status === 'ready'" x-cloak class="mt-4 text-sm" style="color: var(--color-danger);" role="alert" x-text="error"></p>
                </div>
            </template>
        </div>

        <p class="mt-8 text-xs" style="color: var(--color-text-muted);">
            Warst du noch nicht angemeldet, melde dich an und öffne den Link aus dem Desktop-Assistenten erneut - der Code bleibt zehn Minuten gültig.
        </p>
    </div>
</div>
