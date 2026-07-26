<?php

namespace Plugins\typemillupdate\Models;

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

    /** How long chunks and assembled archives are kept before being swept. */
    public const STALE_SECONDS = 21600; // 6 hours

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
            return self::problem('Invalid upload.', 'err_upload_invalid');
        }

        if (strlen($base64) > self::MAX_CHUNK_BYTES) {
            return self::problem('Upload chunk is too large.', 'err_upload_chunk_too_large');
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return self::problem('Upload chunk could not be decoded.', 'err_upload_chunk_decode');
        }

        $directory = $this->chunkDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return self::problem('Could not create the upload directory.', 'err_upload_directory');
        }

        if (@file_put_contents($directory . DIRECTORY_SEPARATOR . $safeId . '.' . $index, $decoded) === false) {
            return self::problem('Could not store the upload chunk.', 'err_upload_store');
        }

        return ['ok' => true, 'error' => null, 'error_key' => null, 'error_params' => []];
    }

    /** @see Release::problem() - same contract, so the panel can translate it. */
    private static function problem(string $message, string $key, array $params = []): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'error_key' => 'typemillupdate.' . $key,
            'error_params' => $params,
        ];
    }

    /**
     * Join the chunks into one archive. The running total is capped, because
     * the size of the whole is only known once it is assembled.
     */
    public function assemble(string $uploadId, int $total, string $targetFile): array
    {
        $safeId = self::sanitizeId($uploadId);
        if ($safeId === null || $total < 1) {
            return self::problem('Invalid upload.', 'err_upload_invalid');
        }

        $directory = $this->chunkDirectory();
        $out = @fopen($targetFile, 'wb');
        if ($out === false) {
            return self::problem('Could not open the target file for writing.', 'err_upload_target');
        }

        $written = 0;
        for ($i = 0; $i < $total; $i++) {
            $chunkPath = $directory . DIRECTORY_SEPARATOR . $safeId . '.' . $i;

            if (!is_file($chunkPath)) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return self::problem(
                    'The upload is incomplete; chunk ' . $i . ' is missing.',
                    'err_upload_incomplete',
                    ['chunk' => $i]
                );
            }

            $chunk = (string) @file_get_contents($chunkPath);
            $written += strlen($chunk);

            if ($written > Release::MAX_DOWNLOAD_BYTES) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return self::problem(
                    'The uploaded file is larger than ' . Environment::formatBytes(Release::MAX_DOWNLOAD_BYTES) . '.',
                    'err_upload_too_large',
                    ['limit' => Environment::formatBytes(Release::MAX_DOWNLOAD_BYTES)]
                );
            }

            if (@fwrite($out, $chunk) === false) {
                fclose($out);
                @unlink($targetFile);
                $this->discard($uploadId, $total);

                return self::problem('Could not write the uploaded file.', 'err_upload_write');
            }
        }

        fclose($out);
        $this->discard($uploadId, $total);

        if ($written === 0) {
            @unlink($targetFile);

            return self::problem('The uploaded file is empty.', 'err_upload_empty');
        }

        return ['ok' => true, 'error' => null, 'error_key' => null, 'error_params' => [], 'bytes' => $written];
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
     * Drop chunks and archives that nothing is waiting for any more.
     *
     * An upload the browser abandoned leaves its chunks behind, and an archive
     * the admin never confirmed stays in the working directory; without a sweep
     * both accumulate for as long as the site stands.
     *
     * Age is the only usable signal. An upload is many requests long, so at no
     * point are another admin's pieces known to be finished - deleting whatever
     * is not the caller's own would cut a colleague's upload in half.
     */
    public function purgeStale(int $maxAgeSeconds = self::STALE_SECONDS): void
    {
        $cutoff = time() - max($maxAgeSeconds, 0);
        $work = $this->environment->workPath();

        if (!is_dir($work)) {
            return;
        }

        foreach ((array) @scandir($work) as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, 'upload-') || !str_ends_with($entry, '.zip')) {
                continue;
            }

            $path = $work . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && (int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }

        $chunks = $this->chunkDirectory();
        if (!is_dir($chunks)) {
            return;
        }

        foreach ((array) @scandir($chunks) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $chunks . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && (int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }

        // Succeeds only once the last chunk is gone.
        @rmdir($chunks);
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
