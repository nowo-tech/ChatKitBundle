<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use finfo;
use InvalidArgumentException;
use Normalizer;
use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;

use function array_keys;
use function array_unique;
use function array_values;
use function class_exists;
use function fclose;
use function fopen;
use function fread;
use function in_array;
use function is_string;
use function mb_strcut;
use function ord;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const FILEINFO_MIME_TYPE;

/**
 * 2.7 (spec 259, Q-259-11): which files the chat takes, and what they are. The server decides the type: ext-fileinfo's
 * magic (`finfo`) **and** the extension's allow-list must agree, and the stored type comes from the allow-list, never
 * from the client. Office files and archives are recognised by their first bytes only (never decompressed).
 *
 * Groups on by default: image, pdf, text, markdown, code, office, audio, video. `archive` is opt-in. SVG, HTML as a
 * page, executables and anything else are never in a group: a host adds an extension to `extra_types` (kind `other`,
 * always a download, the sniffed type must equal the declared one).
 *
 * Pure and stateless (the table is built from the configuration at container build).
 */
final readonly class ChatAttachmentTypes
{
    /** The groups (`attachments.types`). */
    public const array GROUPS = ['image', 'pdf', 'text', 'markdown', 'code', 'office', 'audio', 'video', 'archive'];

    /** The groups on by default (Q-259-11: archives are opt-in). */
    public const array DEFAULT_GROUPS = ['image', 'pdf', 'text', 'markdown', 'code', 'office', 'audio', 'video'];

    /** Longest stored name, in bytes. */
    public const int NAME_MAX_BYTES = 255;

    /** Longest extension. */
    public const int EXTENSION_MAX = 16;

    /** Sniffed types a text-like file may have (finfo answers `text/*` for most sources). */
    private const array TEXTUAL = ['application/json', 'application/javascript', 'application/x-javascript', 'application/xml', 'application/x-ndjson', 'application/csv', 'application/x-wine-extension-ini'];

    /**
     * extension => [group, stored media type, sniffed types it accepts (`text` = any textual type)].
     *
     * @var array<string, array{0: string, 1: string, 2: 'text'|list<string>}>
     */
    private const array TABLE = [
        // Images: the exact raster type (StorageKit checks the magic again).
        'png'  => ['image', 'image/png', ['image/png']],
        'jpg'  => ['image', 'image/jpeg', ['image/jpeg']],
        'jpeg' => ['image', 'image/jpeg', ['image/jpeg']],
        'gif'  => ['image', 'image/gif', ['image/gif']],
        'webp' => ['image', 'image/webp', ['image/webp']],
        'pdf'  => ['pdf', 'application/pdf', ['application/pdf']],
        // Text: served as itself (always a download), never rendered.
        'txt' => ['text', 'text/plain', 'text'],
        'log' => ['text', 'text/plain', 'text'],
        'csv' => ['text', 'text/csv', 'text'],
        'tsv' => ['text', 'text/tab-separated-values', 'text'],
        // Markdown.
        'md'       => ['markdown', 'text/markdown', 'text'],
        'markdown' => ['markdown', 'text/markdown', 'text'],
        // Code: always stored as text/plain (an .html or .svg source is text, never a page or an image).
        'php'   => ['code', 'text/plain', 'text'], 'js' => ['code', 'text/plain', 'text'], 'mjs' => ['code', 'text/plain', 'text'],
        'ts'    => ['code', 'text/plain', 'text'], 'tsx' => ['code', 'text/plain', 'text'], 'jsx' => ['code', 'text/plain', 'text'],
        'json'  => ['code', 'text/plain', 'text'], 'yaml' => ['code', 'text/plain', 'text'], 'yml' => ['code', 'text/plain', 'text'],
        'xml'   => ['code', 'text/plain', 'text'], 'html' => ['code', 'text/plain', 'text'], 'htm' => ['code', 'text/plain', 'text'],
        'css'   => ['code', 'text/plain', 'text'], 'scss' => ['code', 'text/plain', 'text'], 'sql' => ['code', 'text/plain', 'text'],
        'sh'    => ['code', 'text/plain', 'text'], 'py' => ['code', 'text/plain', 'text'], 'go' => ['code', 'text/plain', 'text'],
        'rs'    => ['code', 'text/plain', 'text'], 'java' => ['code', 'text/plain', 'text'], 'kt' => ['code', 'text/plain', 'text'],
        'c'     => ['code', 'text/plain', 'text'], 'h' => ['code', 'text/plain', 'text'], 'cpp' => ['code', 'text/plain', 'text'],
        'cs'    => ['code', 'text/plain', 'text'], 'rb' => ['code', 'text/plain', 'text'], 'swift' => ['code', 'text/plain', 'text'],
        'ini'   => ['code', 'text/plain', 'text'], 'toml' => ['code', 'text/plain', 'text'], 'diff' => ['code', 'text/plain', 'text'],
        'patch' => ['code', 'text/plain', 'text'], 'twig' => ['code', 'text/plain', 'text'], 'vue' => ['code', 'text/plain', 'text'],
        // Office: OOXML / ODF (ZIP) and legacy OLE; checked by their first bytes, never decompressed.
        'docx' => ['office', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['zip']],
        'xlsx' => ['office', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['zip']],
        'pptx' => ['office', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ['zip']],
        'odt'  => ['office', 'application/vnd.oasis.opendocument.text', ['zip']],
        'ods'  => ['office', 'application/vnd.oasis.opendocument.spreadsheet', ['zip']],
        'odp'  => ['office', 'application/vnd.oasis.opendocument.presentation', ['zip']],
        'doc'  => ['office', 'application/msword', ['ole']],
        'xls'  => ['office', 'application/vnd.ms-excel', ['ole']],
        'ppt'  => ['office', 'application/vnd.ms-powerpoint', ['ole']],
        // Audio and video (played inline by the browser from the signed URL).
        'mp3'  => ['audio', 'audio/mpeg', ['audio/mpeg', 'audio/mp3']],
        'm4a'  => ['audio', 'audio/mp4', ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'aac'  => ['audio', 'audio/aac', ['audio/aac', 'audio/x-hx-aac-adts']],
        'ogg'  => ['audio', 'audio/ogg', ['audio/ogg', 'application/ogg']],
        'oga'  => ['audio', 'audio/ogg', ['audio/ogg', 'application/ogg']],
        'opus' => ['audio', 'audio/ogg', ['audio/ogg', 'application/ogg']],
        'wav'  => ['audio', 'audio/wav', ['audio/wav', 'audio/x-wav', 'audio/vnd.wave']],
        'mp4'  => ['video', 'video/mp4', ['video/mp4']],
        'webm' => ['video', 'video/webm', ['video/webm', 'audio/webm']],
        // Archives (opt-in): never listed or extracted.
        'zip' => ['archive', 'application/zip', ['application/zip']],
        '7z'  => ['archive', 'application/x-7z-compressed', ['application/x-7z-compressed']],
        'gz'  => ['archive', 'application/gzip', ['application/gzip', 'application/x-gzip']],
        'tar' => ['archive', 'application/x-tar', ['application/x-tar']],
    ];

    /** Control, format (bidi, zero-width), line and paragraph separator characters: never kept in a name. */
    private const string UNSAFE = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u';

    /** @var array<string, array{0: string, 1: string, 2: 'text'|list<string>}> */
    private array $table;

    /**
     * @param list<string> $groups The groups on (`attachments.types`)
     * @param array<string, string> $extraTypes Extra extensions (`attachments.extra_types`): extension => media type
     *
     * @throws InvalidArgumentException On an unknown group or an invalid extra type
     */
    public function __construct(array $groups = self::DEFAULT_GROUPS, array $extraTypes = [])
    {
        foreach ($groups as $group) {
            if (!in_array($group, self::GROUPS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown attachment type group "%s".', $group));
            }
        }
        $table = [];
        foreach (self::TABLE as $extension => $row) {
            if (in_array($row[0], $groups, true)) {
                $table[$extension] = $row;
            }
        }
        foreach ($extraTypes as $extension => $mediaType) {
            if (!is_string($extension) || preg_match('/^[a-z0-9]{1,16}\z/', $extension) !== 1 || preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $mediaType) !== 1) {
                throw new InvalidArgumentException(sprintf('attachments.extra_types: "%s" => "%s" needs a lower-case extension and a bare lower-case media type.', $extension, $mediaType));
            }
            $table[$extension] = ['other', $mediaType, [$mediaType]];
        }
        $this->table = $table;
    }

    /**
     * Sanitizes a client file name (spec 259 §21): NFC, control and bidirectional characters dropped, `/ \ :` and other
     * separators replaced, leading dots and spaces trimmed, at most 255 bytes; the extension (lower case, ≤ 16
     * characters of `[a-z0-9]`) kept at the end.
     *
     * @return array{0: string, 1: string}|null [name, extension]; null when nothing usable is left
     */
    public static function sanitizeName(string $name): ?array
    {
        if (preg_match('//u', $name) !== 1) {
            return null;
        }
        if (class_exists(Normalizer::class)) {
            $name = (string) Normalizer::normalize($name, Normalizer::FORM_C);
        }
        $name = (string) preg_replace(self::UNSAFE, '', $name);
        $name = (string) preg_replace('#[/\\\\:*?"<>|]+#', '_', $name);
        $name = trim((string) preg_replace('/^[.\s]+/u', '', trim($name)));
        if ($name === '') {
            return null;
        }
        $dot       = strrpos($name, '.');
        $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
        if ($extension !== '' && preg_match('/^[a-z0-9]{1,' . self::EXTENSION_MAX . '}\z/', $extension) !== 1) {
            $extension = '';
        }
        if (strlen($name) > self::NAME_MAX_BYTES) {
            $suffix = $extension === '' ? '' : '.' . $extension;
            $base   = $extension === '' ? $name : substr($name, 0, (int) $dot);
            $name   = trim(mb_strcut($base, 0, self::NAME_MAX_BYTES - strlen($suffix), 'UTF-8')) . $suffix;
        }

        return [$name, $extension];
    }

    /**
     * What a local file is: its extension must be allowed and its bytes must match it.
     *
     * @param string $path A local regular file
     * @param string $extension Its sanitized extension
     *
     * @throws ChatAttachmentRefused `file_type_not_allowed` (extension), `type_mismatch` (bytes)
     *
     * @return array{0: string, 1: ChatAttachmentKind} [stored media type, kind]
     */
    public function classify(string $path, string $extension): array
    {
        $row = $this->table[$extension] ?? null;
        if ($row === null) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::TYPE_NOT_ALLOWED);
        }
        [$group, $mediaType, $accepts] = $row;
        $head                          = $this->head($path);
        $sniffed                       = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $matches                       = match (true) {
            $accepts === 'text'  => $this->isTextual($sniffed, $head),
            $accepts === ['zip'] => $this->isOfficeZip($head, $extension),
            $accepts === ['ole'] => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            default              => in_array($sniffed, $accepts, true),
        };
        if (!$matches) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::TYPE_MISMATCH);
        }

        return [$mediaType, ChatAttachmentKind::from($group)];
    }

    /**
     * Every media type a file may be stored with (StorageKit's context allow-list).
     *
     * @return list<string>
     */
    public function acceptedTypes(): array
    {
        $types = [];
        foreach ($this->table as $row) {
            $types[] = $row[1];
        }

        return array_values(array_unique($types));
    }

    /**
     * The allowed extensions (for the file picker's `accept` attribute and `me.limits`).
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_keys($this->table);
    }

    /**
     * The kinds on.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [];
        foreach ($this->table as $row) {
            $kinds[$row[0]] = $row[0];
        }

        return array_values($kinds);
    }

    /** The first bytes of the file (never more than 4 KiB). */
    private function head(string $path): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::INVALID_FILE);
        }
        try {
            return (string) fread($handle, 4096);
        } finally {
            fclose($handle);
        }
    }

    /** A text file: a textual sniffed type and no NUL byte in its first 4 KiB. */
    private function isTextual(string $sniffed, string $head): bool
    {
        return !str_contains($head, "\0") && (str_starts_with($sniffed, 'text/') || in_array($sniffed, self::TEXTUAL, true));
    }

    /**
     * An OOXML or ODF file by its first local ZIP header, never decompressed: ODF starts with a stored `mimetype` entry
     * holding its type; OOXML's first entry is `[Content_Types].xml`, `_rels/` or `docProps/`.
     */
    private function isOfficeZip(string $head, string $extension): bool
    {
        if (!str_starts_with($head, "PK\x03\x04") || strlen($head) < 30) {
            return false;
        }
        $nameLength = ord($head[26]) | (ord($head[27]) << 8);
        $extra      = ord($head[28]) | (ord($head[29]) << 8);
        $entry      = substr($head, 30, $nameLength);
        if (in_array($extension, ['odt', 'ods', 'odp'], true)) {
            $expected = self::TABLE[$extension][1];

            return $entry === 'mimetype' && substr($head, 30 + $nameLength + $extra, strlen($expected)) === $expected;
        }

        return $entry === '[Content_Types].xml' || str_starts_with($entry, '_rels/') || str_starts_with($entry, 'docProps/');
    }
}
