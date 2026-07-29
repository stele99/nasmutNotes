<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Log\LogColumnType;
use App\Domain\Notes\ProseMirrorHtmlRenderer;
use App\Domain\Notes\ProseMirrorValidator;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;

final class PublicShareService
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly PageRepository $pages,
        private readonly NoteContentRepository $notes,
        private readonly CategoryRepository $categories,
        private readonly TaskRepository $tasks,
        private readonly LogRepository $log,
        private readonly NoteAttachmentRepository $images,
        private readonly PageAttachmentRepository $files,
        private readonly UploadStorage $storage,
        private readonly ProseMirrorValidator $validator,
        private readonly ProseMirrorHtmlRenderer $renderer,
    ) {
    }

    /** @return array<string, mixed> */
    public function resolve(string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }
        $share = $this->shares->findActiveByTokenHash(hash('sha256', $token));
        if ($share === null) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }

        return $share;
    }

    /** @return array<string, mixed> */
    public function view(string $token): array
    {
        $share = $this->resolve($token);
        $this->assertPublicMode($share);
        $page = $this->pages->findById((int) $share['page_id']);
        if ($page === null || $page['deleted_at'] !== null) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }

        $data = [
            'share' => $share,
            'page' => ['id' => (int) $page['id'], 'type' => $page['type'], 'title' => $page['title']],
            'note_html' => null,
            'categories' => [],
            'log_columns' => [],
            'log_entries' => [],
            'attachments' => array_map(static fn (array $file): array => [
                'id' => (int) $file['id'],
                'name' => (string) $file['original_name'],
                'byte_size' => (int) $file['byte_size'],
            ], $this->files->listForPage((int) $page['id'])),
        ];

        if ($page['type'] === 'note') {
            $content = $this->notes->find((int) $page['id']);
            $document = json_decode((string) ($content['content'] ?? ''), true);
            if (!is_array($document)) {
                $document = ['type' => 'doc', 'content' => []];
            }
            $this->validator->validate($document);
            $data['note_html'] = $this->renderer->render($document, $token);
        } elseif ($page['type'] === 'log') {
            $data['log_columns'] = array_map(static fn (array $column): array => [
                'id' => (int) $column['id'],
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
                'is_numeric' => LogColumnType::from((string) $column['type'])->isNumeric(),
            ], $this->log->columnsForPage((int) $page['id']));

            $data['log_entries'] = array_map(static function (array $entry): array {
                $values = [];
                foreach ((array) ($entry['values'] ?? []) as $columnId => $value) {
                    $values[(int) $columnId] = $value['value_text'] !== null
                        ? (string) $value['value_text']
                        : ($value['value_number'] !== null ? (string) $value['value_number'] : '');
                }

                $values['user'] = $entry['created_by_name'] !== null ? (string) $entry['created_by_name'] : '';

                return ['id' => (int) $entry['id'], 'occurred_at' => (string) $entry['occurred_at'], 'values' => $values];
            }, $this->log->entriesForPage((int) $page['id'], null, false));
        } else {
            $data['categories'] = array_map(function (array $category): array {
                $category['tasks'] = $this->tasks->listForCategory((int) $category['id']);

                return $category;
            }, $this->categories->listForPage((int) $page['id']));
        }

        return $data;
    }

    /** @return array{path: string, mime_type: string, byte_size: int} */
    public function image(string $shareToken, string $imageToken): array
    {
        $share = $this->resolve($shareToken);
        $this->assertPublicMode($share);
        if (preg_match('/^[a-f0-9]{64}$/', $imageToken) !== 1) {
            throw new NotFoundException('Bild nicht gefunden.');
        }
        $image = $this->images->findByTokenHash(hash('sha256', $imageToken));
        if ($image === null || (int) $image['page_id'] !== (int) $share['page_id']) {
            throw new NotFoundException('Bild nicht gefunden.');
        }
        $path = $this->storage->path((string) $image['storage_name']);
        if ($path === null) {
            throw new NotFoundException('Bild nicht gefunden.');
        }

        return ['path' => $path, 'mime_type' => (string) $image['mime_type'], 'byte_size' => (int) $image['byte_size']];
    }

    /** @return array{path: string, mime_type: string, byte_size: int, original_name: string} */
    public function file(string $shareToken, int $attachmentId): array
    {
        $share = $this->resolve($shareToken);
        $this->assertPublicMode($share);
        $file = $this->files->findById($attachmentId);
        if ($file === null || (int) $file['page_id'] !== (int) $share['page_id']) {
            throw new NotFoundException('Datei nicht gefunden.');
        }
        $path = $this->storage->path((string) $file['storage_name']);
        if ($path === null) {
            throw new NotFoundException('Datei nicht gefunden.');
        }

        return [
            'path' => $path,
            'mime_type' => (string) $file['mime_type'],
            'byte_size' => (int) $file['byte_size'],
            'original_name' => (string) $file['original_name'],
        ];
    }

    /** @param array<string, mixed> $share */
    public function recordView(array $share): void
    {
        $this->shares->recordPublicView((int) $share['share_id']);
    }

    /** @param array<string, mixed> $share */
    private function assertPublicMode(array $share): void
    {
        if (!in_array($share['mode'] ?? null, ['read', 'read_copy'], true)) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }
    }
}
