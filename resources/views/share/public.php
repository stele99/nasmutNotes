<main class="min-h-screen px-5 py-8 sm:px-10 sm:py-12">
    <div class="mx-auto max-w-4xl">
        <header class="mb-10 flex flex-col gap-5 border-b pb-6 sm:flex-row sm:items-center sm:justify-between" style="border-color: var(--color-border);">
            <div class="min-w-0">
                <a href="/" class="text-sm font-semibold"><span style="color: var(--color-danger);">nasmut</span>Notes</a>
                <p class="mt-2 text-sm" style="color: var(--color-text-muted);"><?= ($share['mode'] ?? '') === 'read_copy' ? 'Nur lesen · Kopieren erlaubt' : (($share['mode'] ?? '') === 'write' ? 'Anmeldung erforderlich' : 'Nur lesen') ?></p>
            </div>
            <?php if (($share['mode'] ?? '') === 'read_copy'): ?>
                <?php if (empty($authenticated)): ?>
                    <a href="<?= e((string) $loginUrl) ?>" class="btn btn-primary">Anmelden und kopieren</a>
                <?php else: ?>
                    <form action="/s/<?= e((string) $token) ?>/copy" method="post" data-copy-form class="flex flex-col gap-2 sm:flex-row">
                        <label class="sr-only" for="copy-notebook">Zielnotizbuch</label>
                        <select id="copy-notebook" name="notebook_id" class="rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border); background: var(--color-bg);">
                            <option value="">Nicht zugewiesen</option>
                            <?php foreach ($notebooks as $notebook): ?><option value="<?= (int) $notebook['id'] ?>"><?= e((string) $notebook['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Kopie erstellen</button>
                        <p data-copy-error class="text-sm" style="color: var(--color-danger);" role="alert"></p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </header>

        <?php if (!empty($loginRequired)): ?>
            <section class="mx-auto max-w-lg text-center">
                <h1 class="text-3xl font-semibold tracking-tight"><?= e((string) $page['title']) ?></h1>
                <p class="mt-4" style="color: var(--color-text-muted);">Melde dich an, um diese Seite zu lesen und zu bearbeiten. Sie erscheint danach unter „Geteilt“.</p>
                <a href="<?= e((string) $loginUrl) ?>" class="btn btn-primary mt-6">Mit Google anmelden</a>
            </section>
        <?php else: ?>
            <article>
                <h1 class="mb-8 text-4xl font-semibold tracking-tight sm:text-5xl"><?= e((string) $page['title']) ?></h1>
                <?php if ($page['type'] === 'note'): ?>
                    <div class="public-note-content"><?= $note_html ?></div>
                <?php elseif ($page['type'] === 'log'): ?>
                    <div class="overflow-x-auto rounded-lg border" style="border-color: var(--color-border);">
                        <table class="w-full text-sm">
                            <thead>
                                <tr style="color: var(--color-text-muted);">
                                    <th class="px-3 py-2 text-left">Zeitpunkt</th>
                                    <?php foreach ($log_columns as $column): ?>
                                        <th class="px-3 py-2 text-left"><?= e((string) $column['name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($log_entries as $entry): ?>
                                    <tr class="border-t" style="border-color: var(--color-border);">
                                        <td class="whitespace-nowrap px-3 py-2 font-medium"><?= e(str_replace('T', ' ', substr((string) $entry['occurred_at'], 0, 16))) ?></td>
                                        <?php foreach ($log_columns as $column): ?>
                                            <td class="px-3 py-2"><?= e((string) ($entry['values'][$column['id']] ?? '')) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($log_entries === []): ?>
                                    <tr><td class="px-3 py-6 text-center" style="color: var(--color-text-muted);" colspan="<?= count($log_columns) + 1 ?>">Noch keine Einträge.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="space-y-8">
                        <?php foreach ($categories as $category): ?>
                            <section><h2 class="text-xl font-semibold"><?= e((string) $category['name']) ?></h2><div class="mt-3 divide-y" style="border-color: var(--color-border);">
                                <?php foreach ($category['tasks'] as $task): ?><div class="flex gap-3 py-3"><span aria-hidden="true"><?= !empty($task['is_done']) ? '☑' : '☐' ?></span><div><p class="font-medium<?= !empty($task['is_done']) ? ' line-through' : '' ?>"><?= e((string) $task['title']) ?></p><?php if (!empty($task['description'])): ?><p class="mt-1 text-sm" style="color: var(--color-text-muted);"><?= nl2br(e((string) $task['description'])) ?></p><?php endif; ?></div></div><?php endforeach; ?>
                            </div></section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($attachments !== []): ?><section class="mt-10 border-t pt-6" style="border-color: var(--color-border);"><h2 class="font-semibold">Anhänge</h2><div class="mt-3 flex flex-wrap gap-2"><?php foreach ($attachments as $attachment): ?><a class="attachment-badge-open rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border);" href="/s/<?= e((string) $token) ?>/files/<?= (int) $attachment['id'] ?>"><?= e((string) $attachment['name']) ?></a><?php endforeach; ?></div></section><?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</main>
