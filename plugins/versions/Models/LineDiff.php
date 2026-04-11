<?php

namespace Plugins\versions\Models;

class LineDiff
{
    public function compare(string $oldText, string $newText): array
    {
        $oldLines = $this->splitLines($oldText);
        $newLines = $this->splitLines($newText);

        $operations = $this->buildOperations($oldLines, $newLines);

        $stats = [
            'added' => 0,
            'removed' => 0,
        ];

        foreach ($operations as $operation) {
            if ($operation['type'] === 'add') {
                $stats['added']++;
            }
            if ($operation['type'] === 'remove') {
                $stats['removed']++;
            }
        }

        return [
            'lines' => $operations,
            'stats' => $stats,
        ];
    }

    private function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return preg_split("/\r\n|\n|\r/", $text);
    }

    private function buildOperations(array $oldLines, array $newLines): array
    {
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        $matrix = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                if ($oldLines[$i] === $newLines[$j]) {
                    $matrix[$i][$j] = $matrix[$i + 1][$j + 1] + 1;
                } else {
                    $matrix[$i][$j] = max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
                }
            }
        }

        $operations = [];
        $i = 0;
        $j = 0;
        $oldLineNumber = 1;
        $newLineNumber = 1;

        while ($i < $oldCount && $j < $newCount) {
            if ($oldLines[$i] === $newLines[$j]) {
                $operations[] = [
                    'type' => 'context',
                    'line' => $oldLines[$i],
                    'old_line' => $oldLineNumber,
                    'new_line' => $newLineNumber,
                ];
                $i++;
                $j++;
                $oldLineNumber++;
                $newLineNumber++;
                continue;
            }

            if ($matrix[$i + 1][$j] >= $matrix[$i][$j + 1]) {
                $operations[] = [
                    'type' => 'remove',
                    'line' => $oldLines[$i],
                    'old_line' => $oldLineNumber,
                    'new_line' => null,
                ];
                $i++;
                $oldLineNumber++;
                continue;
            }

            $operations[] = [
                'type' => 'add',
                'line' => $newLines[$j],
                'old_line' => null,
                'new_line' => $newLineNumber,
            ];
            $j++;
            $newLineNumber++;
        }

        while ($i < $oldCount) {
            $operations[] = [
                'type' => 'remove',
                'line' => $oldLines[$i],
                'old_line' => $oldLineNumber,
                'new_line' => null,
            ];
            $i++;
            $oldLineNumber++;
        }

        while ($j < $newCount) {
            $operations[] = [
                'type' => 'add',
                'line' => $newLines[$j],
                'old_line' => null,
                'new_line' => $newLineNumber,
            ];
            $j++;
            $newLineNumber++;
        }

        return $operations;
    }
}
