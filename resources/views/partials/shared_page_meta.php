<?php if (!empty($page['is_shared']) && empty($page['can_edit'])): ?>
    <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs" style="color: var(--color-text-muted);">
        <span>Zuletzt geändert: <time data-updated-at="<?= e((string) ($page['updated_at'] ?? '')) ?>" x-text="sharedPageDateLabel($el.dataset.updatedAt)"></time></span>
        <span>Owner: <?= e((string) ($page['owner_name'] ?? 'Unbekannt')) ?></span>
    </p>
<?php endif; ?>
