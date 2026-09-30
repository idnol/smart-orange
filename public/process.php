<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Один крок імпорту: до step_max_rows рядків або step_max_seconds секунд.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $importer = new Importer(Db::get(), config());
    json_response($importer->step((int) ($_POST['import_id'] ?? 0)));
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage(), 'fatal' => true], 422);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
