<main class="min-h-screen px-5 py-8 sm:px-10 sm:py-12">
    <div class="mx-auto max-w-lg">
        <header class="mb-10 border-b pb-6" style="border-color: var(--color-border);">
            <a href="/" class="text-sm font-semibold"><span style="color: var(--color-danger);">nasmut</span>Notes</a>
        </header>

        <section class="text-center">
            <h1 class="mt-4 text-2xl font-semibold tracking-tight"><?= e((string) $page['title']) ?></h1>
            <p class="mt-2 text-sm" style="color: var(--color-text-muted);">Diese Freigabe ist kennwortgeschützt.</p>

            <form data-unlock-form action="/s/<?= e((string) $token) ?>/unlock" class="mt-6 flex flex-col items-stretch gap-3">
                <label for="unlock-password" class="sr-only">Kennwort</label>
                <input id="unlock-password" name="password" type="password" autofocus required class="rounded-md border px-3 py-2.5 text-base" style="border-color: var(--color-border); background: var(--color-bg);">
                <button type="submit" class="btn btn-primary">Entsperren</button>
                <p data-unlock-error class="text-sm" style="color: var(--color-danger);" role="alert"></p>
            </form>
        </section>
    </div>
</main>
