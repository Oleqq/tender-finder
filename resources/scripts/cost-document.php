<?php

use App\Services\CostGap\DocumentParseException;
use App\Services\CostGap\DocumentParser;

// Separate CLI worker: file paths are supplied by the authorized server, never by a client.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $data = (new DocumentParser)->parse($argv[1] ?? '', $argv[2] ?? '');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // PDF internals and document content must not be included in an HTTP response or log.
    $message = $error instanceof DocumentParseException
        ? $error->getMessage() : 'Не удалось извлечь текст. Попробуйте DOCX, TXT или вставьте выдержку вручную.';
    echo json_encode(['ok' => false, 'message' => mb_substr($message, 0, 400)], JSON_UNESCAPED_UNICODE);
    exit(1);
}
