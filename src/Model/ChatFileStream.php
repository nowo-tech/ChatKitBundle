<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use Closure;
use LogicException;

use function sprintf;

/**
 * 2.7 (spec 259): a bounded, read-only stream of one stored attachment, handed to the host's scanner
 * ({@see ChatScanSubject::$stream}). At most {@see self::$size} bytes are returned. The scanner must not keep it (or the
 * bytes) after `scan()` returns; ChatKit closes it in a `finally` either way. Not cloneable, not serializable: nothing
 * outlives the call (FrankenPHP worker mode).
 */
final class ChatFileStream
{
    /** @var (Closure(int): string)|null */
    private ?Closure $read;

    /** @var (Closure(): bool)|null */
    private ?Closure $eof;

    /** @var (Closure(): void)|null */
    private ?Closure $close;

    /**
     * @param int $size Bytes of the stream
     * @param Closure(int): string $read Up to n more bytes ('' at the end)
     * @param Closure(): bool $eof Whether every byte was read
     * @param Closure(): void $close Releases the source
     *
     * @internal Built by ChatKit over StorageKit's FileReaderInterface
     */
    public function __construct(
        public readonly int $size,
        Closure $read,
        Closure $eof,
        Closure $close,
    ) {
        $this->read  = $read;
        $this->eof   = $eof;
        $this->close = $close;
    }

    /**
     * Closes the source if nobody did.
     */
    // @igor-ignore - Per-call read stream (a value object handed to the scanner, never a service).
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Up to `$length` more bytes; '' at the end.
     *
     * @throws LogicException When the stream is closed
     */
    public function read(int $length = 65536): string
    {
        $read = $this->read ?? throw new LogicException('The chat file stream is closed.');

        return $read($length);
    }

    /**
     * Whether every byte was read (true once closed).
     */
    public function eof(): bool
    {
        return !$this->eof instanceof Closure || ($this->eof)();
    }

    /**
     * Releases the source. Idempotent.
     */
    public function close(): void
    {
        $close = $this->close;
        // @igor-ignore - Per-call read stream (a value object handed to the scanner, never a service).
        $this->read = $this->eof = $this->close = null;
        if ($close instanceof Closure) {
            $close();
        }
    }

    /**
     * @throws LogicException Always: two readers would split the bytes
     */
    public function __clone()
    {
        throw new LogicException(sprintf('%s cannot be cloned.', self::class));
    }

    /**
     * @throws LogicException Always
     *
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException(sprintf('%s cannot be serialized.', self::class));
    }
}
