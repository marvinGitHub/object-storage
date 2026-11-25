<?php

namespace melia\ObjectStorage\Viewer;

/**
 * The log of the viewer, as the log page shows it.
 */
final class LogFile
{
    /**
     * The entries of the log, the newest first: of its last $maxBytes (a line cut there is skipped). Lines that
     * aren't JSON (written before it was) are entries of their own, lines following them without a date belong to
     * them.
     *
     * @return list<array{datetime: string, level: string, message: string, storage: ?string}>
     */
    public static function read(string $file, int $maxBytes = 2 * 1024 * 1024): array
    {
        if (!is_file($file) || false === ($handle = fopen($file, 'rb'))) {
            return [];
        }
        try {
            $size = (int)fstat($handle)['size'];
            $offset = max(0, $size - $maxBytes);
            fseek($handle, $offset);
            $text = (string)stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        if ($offset > 0) {
            array_shift($lines);
        }

        $entries = [];
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['message'])) {
                $entries[] = [
                    'datetime' => (string)($data['datetime'] ?? ''),
                    'level' => (string)($data['level_name'] ?? 'ERROR'),
                    'message' => (string)$data['message'],
                    'storage' => isset($data['context']['storage']) ? (string)$data['context']['storage'] : null,
                ];
            } elseif (1 === preg_match('/^\[([^]]+)] [\w.-]+\.(\w+): (.*)$/s', $line, $match)) {
                $entries[] = ['datetime' => $match[1], 'level' => $match[2], 'message' => $match[3], 'storage' => null];
            } elseif ([] !== $entries) {
                $entries[count($entries) - 1]['message'] .= "\n" . $line;
            } else {
                $entries[] = ['datetime' => '', 'level' => 'ERROR', 'message' => $line, 'storage' => null];
            }
        }
        return array_reverse($entries);
    }
}
