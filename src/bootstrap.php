<?php

declare(strict_types=1);

require __DIR__ . '/Db.php';
require __DIR__ . '/LeadNormalizer.php';
require __DIR__ . '/Importer.php';

function config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../config.php';
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
