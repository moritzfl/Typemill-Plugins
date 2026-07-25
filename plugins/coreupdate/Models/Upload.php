<?php

namespace Plugins\coreupdate\Models;

/**
 * Receives a core archive from the browser in pieces.
 *
 * A release archive is around 2.5 MB, while PHP's default
 * `upload_max_filesize` is 2 MB, so a plain form upload fails on a stock
 * configuration. The browser therefore slices the file and posts base64 chunks
 * as ordinary JSON, which stays far below any upload limit, and the pieces are
 * reassembled here.
 */
class Upload
{
    public const CHUNK_DIRNAME = 'uploads';

    /** Rejects a single oversized chunk before it is decoded. */
    public const MAX_CHUNK_BYTES = 4194304; // 4 MB

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function chunkDirectory(): string
    {
        return $this->environment->workPath() . DIRECTORY_SEPARATOR . self::CHUNK_DIRNAME;
    }

    /**
     * Upload ids come from the browser, and are used to build file paths, so
     * only a conservative character set is accepted.
     */
    public static function sanitizeId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : null;
    }

    public function storeChunk(string $uploadId, int $index, string $base64): array
    {
        $safeId = self::sanitizeId($uploadId);
        if ($safeId === null || $index < 0) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        if (strlen($base64) > self::MAX_CHUNK_BYTES) {
            return ['ok' => false, 'error' => 'Upload chunk is too large.'];
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return ['ok' => false, 'error' => 'Upload chunk could not be decoded.'];
        }

        $directory = $this->chunkDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['ok' => false, 'error' => 'Could not create the upload directory.'];
        }

        if (@file_put_contents($directory . DIRECTORY_SEPARATOR . $safeId . '.' . $index, $decoded) === false) {
            return ['ok' => false, 'error' => 'Could not store the upload chunk.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Join the chunks into one archive. The running total is capped, because
     * the size of the whole is only known once it is assembled.
     */
    public function assemble(string $uploadId, int $total, string $targetFile): array
    {
        $safeId = self::sanitizeId($uploadId);
        if ($safeId === null || $total < 1) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $directory = $this->chunkDirectory();
        $out = @fopen($targetFile, 'wb');
        if ($out === false) {
            return ['ok' => false, 'error' => 'Could not open the target file for writing.'];
        }

        $written = 0;
        for ($i = 0; $i < $total; $i++) {
            $chunkPath = $directory . DIRECTORY_SEPARATOR . $safeId . '.' . $i;

            if (!is_file($chunkPath)) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return ['ok' => false, 'error' => 'The upload is incomplete; chunk ' . $i . ' is missing.'];
            }

            $chunk = (string) @file_get_contents($chunkPath);
            $written += strlen($chunk);

            if ($written > Release::MAX_DOWNLOAD_BYTES) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return ['ok' => false, 'error' => 'The uploaded file is larger than ' . Environment::formatBytes(Release::MAX_DOWNLOAD_BYTES) . '.'];
            }

            if (@fwrite($out, $chunk) === false) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return ['ok' => false, 'error' => 'Could not write the uploaded file.'];
            }
        }

        fclose($out);
        $this->discard($uploadId, $total);

        if ($written === 0) {
            @unlink($targetFile);

            return ['ok' => false, 'error' => 'The uploaded file is empty.'];
        }

        return ['ok' => true, 'error' => null, 'bytes' => $written];
    }

    public function discard(string $uploadId, int $total): void
    {
        $safeId = self::sanitizeId($uploadId);
        if ($safeId === null) {
            return;
        }

        $directory = $this->chunkDirectory();
        for ($i = 0; $i < max($total, 0); $i++) {
            @unlink($directory . DIRECTORY_SEPARATOR . $safeId . '.' . $i);
        }
    }

    /** Name of the assembled archive for an upload. */
    public static function archiveName(string $safeId): string
    {
        return 'upload-' . $safeId . '.zip';
    }

    /**
     * Drop previously assembled archives.
     *
     * Only the most recent upload can be acted on, so an archive left behind by
     * an upload the admin did not confirm would otherwise sit in the working
     * directory until the next install.
     */
    public function discardOtherArchives(string $keepName): void
    {
        $work = $this->environment->workPath();
        if (!is_dir($work)) {
            return;
        }

        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || $entry === $keepName || !str_starts_with($entry, 'upload-')) {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Resolve an assembled archive by name. The name reaches this from the
     * browser, so it is matched against a strict pattern rather than merely
     * checked for traversal segments.
     */
    public function resolveArchive(string $name): ?string
    {
        if (preg_match('/^upload-[A-Za-z0-9_-]{1,64}\.zip$/', $name) !== 1) {
            return null;
        }

        $path = $this->environment->workPath() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }
}
