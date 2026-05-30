<?php

namespace Plugins\versions\Models;

/**
 * Builds ZIP archives with stored (uncompressed) entries — no ext-zip required.
 */
final class StoredZipBuilder
{
    /** @var list<array{name: string, data: string, crc: int, size: int, dosTime: int}> */
    private array $entries = [];

    public function addFromString(string $name, string $data): bool
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return false;
        }

        $this->entries[] = [
            'name' => $name,
            'data' => $data,
            'crc' => $this->unsignedCrc32($data),
            'size' => strlen($data),
            'dosTime' => $this->toDosTimestamp(time()),
        ];

        return true;
    }

    public function addFile(string $name, string $filePath): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            return false;
        }

        return $this->addFromString($name, $data);
    }

    public function build(): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $nameLength = strlen($name);
            $data = $entry['data'];
            $size = $entry['size'];
            $dosTime = $entry['dosTime'];
            $dosDate = ($dosTime >> 16) & 0xffff;
            $dosClock = $dosTime & 0xffff;

            $localHeader = pack(
                'Vv5V3v2',
                0x04034b50,
                10,
                0,
                0,
                $dosClock,
                $dosDate,
                $entry['crc'],
                $size,
                $size,
                $nameLength,
                0
            );

            $localPart = $localHeader . $name . $data;
            $local .= $localPart;

            $centralHeader = pack(
                'Vv6V3v5V2',
                0x02014b50,
                20,
                10,
                0,
                0,
                $dosClock,
                $dosDate,
                $entry['crc'],
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            );

            $central .= $centralHeader . $name;
            $offset += strlen($localPart);
        }

        $centralSize = strlen($central);
        $entryCount = count($this->entries);
        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $entryCount,
            $entryCount,
            $centralSize,
            $offset,
            0
        );

        return $local . $central . $end;
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);

        return ltrim($name, '/');
    }

    private function unsignedCrc32(string $data): int
    {
        return crc32($data) & 0xffffffff;
    }

    private function toDosTimestamp(int $timestamp): int
    {
        $date = getdate($timestamp);
        $year = max(1980, (int) $date['year']);
        $dosDate = (($year - 1980) << 9) | ((int) $date['mon'] << 5) | (int) $date['mday'];
        $dosTime = ((int) $date['hours'] << 11) | ((int) $date['minutes'] << 5) | ((int) $date['seconds'] >> 1);

        return ($dosDate << 16) | $dosTime;
    }
}
