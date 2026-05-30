<?php

namespace Plugins\versions\Models;

final class ExportOptions
{
    /**
     * @param list<string> $mediaFolders Top-level media subfolder names to include
     */
    public function __construct(
        public readonly array $mediaFolders,
        public readonly bool $includeRecycleBin,
    ) {
    }

    /**
     * @param list<string> $availableFolders Valid folder names from the media root
     */
    public static function fromRequestParams(array $params, array $availableFolders): self
    {
        $includeRecycleBin = self::parseBool($params['include_recycle_bin'] ?? null, true);
        $mediaFolders = self::parseMediaFolders($params, $availableFolders);

        return new self($mediaFolders, $includeRecycleBin);
    }

    /**
     * @param list<string> $availableFolders
     */
    public static function defaults(array $availableFolders): self
    {
        return new self($availableFolders, true);
    }

    /**
     * @param list<string> $availableFolders
     *
     * @return list<string>
     */
    private static function parseMediaFolders(array $params, array $availableFolders): array
    {
        if (!array_key_exists('media', $params) && !array_key_exists('media_folders', $params)) {
            return $availableFolders;
        }

        $raw = $params['media'] ?? $params['media_folders'] ?? '';
        if (is_array($raw)) {
            $requested = $raw;
        } else {
            $requested = array_filter(array_map('trim', explode(',', (string) $raw)));
        }

        $allowed = array_flip($availableFolders);

        return array_values(array_filter(
            $requested,
            static fn (string $folder): bool => isset($allowed[$folder])
        ));
    }

    private static function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return !in_array($normalized, ['0', 'false', 'no', 'off'], true);
    }
}
