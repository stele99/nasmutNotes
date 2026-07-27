<div x-show="hasCollaborators()" x-cloak class="relative shrink-0">
    <button
        type="button"
        @click="toggleWriters"
        class="flex -space-x-1.5"
        :aria-expanded="writersOpen"
        :aria-label="sharedWithLabel()"
        :title="sharedWithLabel()"
    >
        <template x-for="writer in visibleWriters()" :key="writer.id">
            <span
                class="relative inline-flex size-7 items-center justify-center rounded-full border-2 text-[10px] font-semibold uppercase"
                style="border-color: var(--color-bg); background: color-mix(in srgb, var(--color-accent) 16%, var(--color-bg)); color: var(--color-accent);"
                x-text="writerInitials(writer)"
            ></span>
        </template>
        <span
            x-show="additionalWritersCount() > 0"
            class="relative inline-flex size-7 items-center justify-center rounded-full border-2 text-[10px] font-semibold"
            style="border-color: var(--color-bg); background: var(--color-bg-subtle); color: var(--color-text-muted);"
            x-text="'+' + additionalWritersCount()"
        ></span>
    </button>

    <div
        x-show="writersOpen"
        x-cloak
        @click.outside="closeWriters"
        @keydown.escape.window="closeWriters"
        class="absolute right-0 top-9 z-50 min-w-56 rounded-lg border p-3 text-left"
        style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);"
    >
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--color-text-muted);">Geteilt mit</p>
        <ul class="mt-2 space-y-1.5">
            <template x-for="writer in writers" :key="writer.id">
                <li class="flex items-center gap-2 text-sm">
                    <span
                        class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold uppercase"
                        style="background: color-mix(in srgb, var(--color-accent) 16%, var(--color-bg)); color: var(--color-accent);"
                        x-text="writerInitials(writer)"
                    ></span>
                    <span class="min-w-0 truncate" x-text="writerLabel(writer)"></span>
                </li>
            </template>
        </ul>
    </div>
</div>
