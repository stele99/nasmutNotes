<div class="flex min-h-screen items-center justify-center px-5" style="background: var(--color-bg-subtle);">
    <div class="document-surface w-full max-w-md p-8 sm:p-10">
        <p class="text-sm font-medium" style="color: var(--color-accent);">Willkommen</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Notizen &amp; Tasks</h1>
        <p class="mb-8 mt-3 text-base" style="color: var(--color-text-muted);">Melde dich mit deinem Google-Konto an, um deinen Workspace zu öffnen.</p>

        <?php if (!empty($error)): ?>
            <div class="mb-5 rounded-lg p-4 text-base" style="background-color: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <a
            href="/auth/google<?= isset($invite) ? '?invite=' . rawurlencode($invite) : '' ?>"
            class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-base font-medium text-white hover:opacity-90"
            style="background: var(--color-accent);"
        >
            Mit Google anmelden
        </a>
    </div>
</div>
