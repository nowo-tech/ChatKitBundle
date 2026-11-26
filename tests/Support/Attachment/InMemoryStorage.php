<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Attachment;

use Closure;
use DateTimeImmutable;
use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\FileRangeDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileReaderInterface;
use Nowo\StorageKitBundle\Download\FileStream;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Lifecycle\RemovedFile;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use function fclose;
use function hash;
use function in_array;
use function stream_get_contents;
use function strlen;
use function substr;

/**
 * 2.7: StorageKit's generic-file ports in memory for the tests: uploads go through the tagged file contexts (as the real
 * uploader does: writableTarget(), acceptedTypes(), maxBytes()), objects and references are kept in arrays, and every
 * call is recorded. `$failNext*` flags make the next call fail. Reset per kernel (a test service).
 */
final class InMemoryStorage implements FileUploaderInterface, FileRemoverInterface, FileReaderInterface, FileRangeDeliveryInterface, FileReferencesInterface
{
    /** @var array<string, array{bytes: string, type: string, context: string, contextId: string, expired: bool, name: string}> */
    public array $objects = [];

    /** @var array<string, string> type:id => text */
    public array $references = [];

    /** @var list<string> */
    public array $calls = [];

    public ?string $failNextUpload = null;

    public bool $failNextRemove = false;

    public bool $failNextExpire = false;

    public bool $failNextRead = false;

    public bool $redirect = false;

    /** @var (Closure(): void)|null Runs once the bytes are stored (race tests). */
    public ?Closure $afterUpload = null;

    /**
     * @param iterable<FileContextInterface> $contexts
     */
    public function __construct(private readonly iterable $contexts = [])
    {
    }

    public function uploadFile(object $user, string $contextType, string $contextId, StoredFileSource $source, string $originalName, string $mediaType): StoredFileView
    {
        $this->calls[] = 'upload';
        if ($this->failNextUpload !== null) {
            $reason               = $this->failNextUpload;
            $this->failNextUpload = null;

            throw new UploadRefused($reason);
        }
        $context = null;
        foreach ($this->contexts as $candidate) {
            if ($candidate->type() === $contextType) {
                $context = $candidate;
            }
        }
        if (!$context instanceof GenericFileContextInterface || $context->writableTarget($user, $contextId) === null) {
            throw new UploadRefused(UploadRefused::CONTEXT);
        }
        if (!in_array($mediaType, $context->acceptedTypes(), true)) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        if ($source->size > $context->maxBytes()) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        $handle = $source->open();
        try {
            $bytes = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $uuid                 = Uuid::v7()->toRfc4122();
        $this->objects[$uuid] = ['bytes' => $bytes, 'type' => $mediaType, 'context' => $contextType, 'contextId' => $contextId, 'expired' => false, 'name' => $originalName];
        if ($this->afterUpload instanceof Closure) {
            ($this->afterUpload)();
        }

        return new StoredFileView($uuid, null, $contextType, $contextId, $originalName, $mediaType, strlen($bytes), 0, 0, hash('sha256', $bytes), strlen($bytes), [], null, null, new DateTimeImmutable());
    }

    public function afterCommit(StoredFileView|RemovedFile ...$uploaded): void
    {
    }

    public function remove(string $uuid, string $contextType, string $contextId): ?RemovedFile
    {
        $this->calls[] = 'remove';
        if ($this->failNextRemove) {
            $this->failNextRemove = false;

            throw new RuntimeException('bucket refused');
        }
        if (!isset($this->objects[$uuid])) {
            return null;
        }
        unset($this->objects[$uuid]);

        return new RemovedFile($uuid, $contextType, $contextId, null, false);
    }

    public function expire(string $uuid, string $contextType, string $contextId): ?RemovedFile
    {
        $this->calls[] = 'expire';
        if ($this->failNextExpire) {
            $this->failNextExpire = false;

            throw new RuntimeException('bucket refused');
        }
        if (!isset($this->objects[$uuid])) {
            return null;
        }
        $this->objects[$uuid]['expired'] = true;

        return new RemovedFile($uuid, $contextType, $contextId, null, true);
    }

    public function open(string $uuid, string $contextType, string $contextId, string $variant, ?int $length = null): FileStream
    {
        $this->calls[] = 'read';
        if ($this->failNextRead) {
            $this->failNextRead = false;

            throw new FileNotDeliverable('gone');
        }
        $object = $this->find($uuid, $contextType, $contextId);

        return new FileStream([$object['bytes']], strlen($object['bytes']), $object['type']);
    }

    public function deliverInContext(string $uuid, string $contextType, string $contextId, string $variant, ?string $range = null, ?bool $inline = null, ?int $ttl = null): DeliverableFile
    {
        $this->calls[] = 'deliver:' . ($inline === true ? 'inline' : 'attachment') . ':' . (string) $ttl . ($range === null ? '' : ':' . $range);
        $object        = $this->find($uuid, $contextType, $contextId);
        if ($this->redirect) {
            return DeliverableFile::redirect('https://bucket.test/' . $uuid . '?X-Amz-Expires=' . (string) $ttl, (int) $ttl);
        }
        $bytes = $object['bytes'];

        return DeliverableFile::stream(static fn (): iterable => [$bytes], strlen($bytes), (int) $ttl, ['Content-Type' => $object['type'], 'Content-Disposition' => 'attachment; filename="file"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function sync(string $type, string $id, string $text, ?array $context): void
    {
        $this->calls[]                       = 'sync';
        $this->references[$type . ':' . $id] = $text;
    }

    public function forget(string $type, array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->references[$type . ':' . $id]);
        }
    }

    /**
     * @return array{bytes: string, type: string, context: string, contextId: string, expired: bool, name: string}
     */
    private function find(string $uuid, string $contextType, string $contextId): array
    {
        $object = $this->objects[$uuid] ?? null;
        if ($object === null || $object['expired'] || $object['context'] !== $contextType || $object['contextId'] !== $contextId) {
            throw new FileNotDeliverable('not found');
        }

        return $object;
    }

    /** The bytes of a stored object (tests). */
    public function bytes(string $uuid): string
    {
        return substr($this->objects[$uuid]['bytes'] ?? '', 0);
    }
}
