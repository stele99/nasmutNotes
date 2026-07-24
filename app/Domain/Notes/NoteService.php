<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteContentRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

final class NoteService
{
    private const MAX_BYTES = 1_000_000;

    public function __construct(
        private readonly PageService $pages,
        private readonly NoteContentRepository $noteContents,
        private readonly ProseMirrorValidator $validator,
    ) {
    }

    /** @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string} */
    public function get(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);

        $row = $this->noteContents->find((int) $page['id']);
        if ($row === null) {
            throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
        }

        return [
            'content' => json_decode((string) $row['content'], true) ?? ['type' => 'doc', 'content' => []],
            'version' => (int) $row['version'],
            'updated_at' => $row['updated_at'],
            'last_editor_name' => $row['last_editor_name'] !== null ? (string) $row['last_editor_name'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string}
     * @throws VersionConflictException bei Versionskonflikt (Kap. 10.4).
     */
    public function save(User $user, int $pageId, array $content, int $expectedVersion): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);

        $this->validator->validate($content);

        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw new ValidationException('Der Notizinhalt überschreitet die maximale Größe von 1 MB.');
        }

        $contentText = $this->validator->extractText($content);

        $saved = $this->noteContents->saveIfVersionMatches(
            (int) $page['id'],
            $encoded,
            $contentText,
            $expectedVersion,
            $user->id,
        );

        if (!$saved) {
            $current = $this->noteContents->find((int) $page['id']);
            assert($current !== null);

            throw new VersionConflictException(
                json_decode((string) $current['content'], true) ?? ['type' => 'doc', 'content' => []],
                (int) $current['version'],
            );
        }

        return $this->get($user, $pageId);
    }

    /** @param array<string, mixed> $page */
    private function assertIsNotePage(array $page): void
    {
        if ($page['type'] !== 'note') {
            throw new NotFoundException('Diese Seite ist keine Notizseite.');
        }
    }
}
