<?php

namespace App\Support;

use Generator;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Nur lesbarer PSR-7-Stream über einen Generator, der Datenblöcke liefert.
 *
 * Damit kann der ZIP-Download entschlüsselte Dateien direkt aus dem Vault
 * übernehmen, ohne dass Klartext in eine Zwischendatei (php://temp) gelangt.
 */
class ChunkStream implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    /**
     * @param  Generator<int, string>  $chunks
     */
    public function __construct(
        private ?Generator $chunks,
        private readonly ?int $size = null,
    ) {}

    public function read(int $length): string
    {
        while (strlen($this->buffer) < $length && $this->hasMoreChunks()) {
            $this->buffer .= (string) $this->chunks->current();
            $this->chunks->next();
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, strlen($data));
        $this->position += strlen($data);

        return $data;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && ! $this->hasMoreChunks();
    }

    public function getContents(): string
    {
        $contents = '';

        while (! $this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isReadable(): bool
    {
        return $this->chunks !== null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Stream ist nicht durchsuchbar.');
    }

    public function rewind(): void
    {
        if ($this->position !== 0) {
            throw new RuntimeException('Stream ist nicht durchsuchbar.');
        }
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream ist nur lesbar.');
    }

    public function close(): void
    {
        $this->chunks = null;
        $this->buffer = '';
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    private function hasMoreChunks(): bool
    {
        if ($this->chunks === null) {
            return false;
        }

        return $this->chunks->valid();
    }
}
