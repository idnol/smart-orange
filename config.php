<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'leads',
        'user' => getenv('DB_USER') ?: 'leads',
        'pass' => getenv('DB_PASS') ?: 'leads',
    ],
    'upload_dir' => __DIR__ . '/storage/uploads',
    // Розмір шматка файлу, який браузер надсилає за один запит (< upload_max_filesize=2M).
    'chunk_size' => 1024 * 1024,
    // Обмеження одного кроку імпорту: жоден запит не наближається до max_execution_time=30.
    'step_max_rows' => 10000,
    'step_max_seconds' => 10,
    'insert_batch' => 500,
];
