<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7-Strom-Dekorateur für den SSE-Durchlauf: Reicht die Antwort des
 * Anbieters chunkweise an Slims Emitter weiter und sammelt nebenbei genug vom
 * Inhalt, um nach dem letzten Chunk die Token-Nutzung zu erfassen.
 *
 * Bewusst ohne Content-Length und ohne Seekbarkeit - genau dann greift in
 * Slims ResponseEmitter die Lese-Schleife bis EOF. Der Merk-puffer ist
 * gekappt: Chat-Antworten sind klein, und geschätzt werden ohnehin nur
 * Tokenzahlen, keine Inhalte.
 */
final class UsageTeeStream implements StreamInterface
{
    private const MAX_TEXT_LENGTH = 1_048_576;

    private const MAX_TAIL_LENGTH = 65_536;

    private string $text = '';

    private string $tail = '';

    private bool $finalized = false;

    private bool $detached = false;

    public function __construct(
        private readonly StreamInterface $upstream,
        /** @var \Closure(string $text, string $tail): void */
        private readonly \Closure $onComplete,
    ) {
    }

    public function __toString(): string
    {
        throw new \RuntimeException('UsageTeeStream kann nicht als String gelesen werden.');
    }

    public function close(): void
    {
        $this->finalize();
        $this->upstream->close();
    }

    public function detach(): mixed
    {
        $this->finalized = true;
        $this->detached = true;

        return $this->upstream->detach();
    }

    public function getSize(): ?int
    {
        // Ohne bekannte Größe wählt der ResponseEmitter die EOF-Lese-Schleife.
        return null;
    }

    public function tell(): int
    {
        return $this->upstream->tell();
    }

    public function eof(): bool
    {
        return $this->upstream->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('UsageTeeStream ist nicht seekbar.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('UsageTeeStream ist nicht rückspulbar.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('UsageTeeStream ist nicht schreibbar.');
    }

    public function isReadable(): bool
    {
        return $this->upstream->isReadable();
    }

    public function read(int $length): string
    {
        if ($this->detached) {
            throw new \RuntimeException('UsageTeeStream ist abgekoppelt.');
        }

        $data = $this->upstream->read($length);
        $this->tee($data);

        if ($this->upstream->eof()) {
            $this->finalize();
        }

        return $data;
    }

    public function getContents(): string
    {
        $data = $this->upstream->getContents();
        $this->tee($data);

        if ($this->upstream->eof()) {
            $this->finalize();
        }

        return $data;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->upstream->getMetadata($key);
    }

    private function tee(string $data): void
    {
        if ($data === '') {
            return;
        }

        if (mb_strlen($this->text) < self::MAX_TEXT_LENGTH) {
            $this->text .= $data;
        }

        $this->tail = mb_substr($this->tail . $data, -self::MAX_TAIL_LENGTH);
    }

    private function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;

        ($this->onComplete)($this->text, $this->tail);
    }
}
