<?php

namespace App\Sales\Application;

use App\Shared\Domain\BusinessRule;

final class CsvParser
{
    public static function parse(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $header = null;
        $rows = [];
        while (($fields = fgetcsv($stream)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h), $fields);

                continue;
            }
            $empty = true;
            foreach ($fields as $f) {
                if (trim((string) $f) !== '') {
                    $empty = false;
                    break;
                }
            }
            if ($empty) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $data[$name] = $fields[$i] ?? null;
                }
            }
            $rows[] = ['rowNumber' => count($rows) + 1, 'data' => $data];
        }
        fclose($stream);
        if ($header === null || $header === [] || count(array_filter($header)) === 0) {
            throw new BusinessRule('EMPTY_CSV', 'File CSV tidak memiliki header pada baris pertama.', 422);
        }

        return [$header, $rows];
    }
}
