<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Приймає один шматок файлу (≤ 1 МБ), тому не впирається в upload_max_filesize / post_max_size.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $importer = new Importer(Db::get(), config());
    $importId = (int) ($_POST['import_id'] ?? 0);

    if ($importId === 0) {
        $filename = trim((string) ($_POST['filename'] ?? ''));
        $size = (int) ($_POST['file_size'] ?? 0);
        if ($filename === '' || $size <= 0) {
            json_response(['error' => 'Порожній файл'], 422);
        }
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            json_response(['error' => 'Потрібен файл .csv'], 422);
        }
        $importId = $importer->create($filename, $size);
    }

    $chunk = $_FILES['chunk'] ?? null;
    if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
        json_response(['error' => 'Шматок файлу не отримано (код ' . ($chunk['error'] ?? '-') . ')', 'import_id' => $importId], 400);
    }

    $result = $importer->appendChunk($importId, (int) ($_POST['offset'] ?? 0), $chunk['tmp_name']);
    json_response(['import_id' => $importId] + $result);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
