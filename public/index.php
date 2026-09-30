<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Db::get();
$imports = $db->query('SELECT * FROM imports ORDER BY id DESC LIMIT 10')->fetchAll();

$importId = (int) ($_GET['import'] ?? ($imports[0]['id'] ?? 0));
$onlyInvalid = !empty($_GET['invalid']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = 'import_id = ?' . ($onlyInvalid ? ' AND is_valid = 0' : '');
$stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $where");
$stmt->execute([$importId]);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $db->prepare("SELECT * FROM leads WHERE $where ORDER BY line_no LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute([$importId]);
$leads = $stmt->fetchAll();

$statusLabels = ['uploading' => 'завантаження', 'processing' => 'обробка', 'done' => 'готово', 'failed' => 'помилка'];
$pageUrl = fn(int $p) => '?' . http_build_query(array_filter(['import' => $importId, 'invalid' => $onlyInvalid ? 1 : null, 'page' => $p]));
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Імпорт заявок</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main>
    <h1>Імпорт заявок</h1>

    <section class="card">
        <form id="import-form">
            <input type="file" id="file" accept=".csv,text/csv" required>
            <button type="submit" id="start">Імпортувати</button>
        </form>
        <div id="progress" hidden>
            <div class="bar"><div class="bar-fill" id="bar-fill"></div></div>
            <p id="progress-text"></p>
        </div>
        <p class="hint">
            Файл передається шматками по 1&nbsp;МБ і обробляється кроками до
            <?= config()['step_max_rows'] ?> рядків, тож імпорт вкладається у стандартні
            <code>upload_max_filesize=<?= e(ini_get('upload_max_filesize')) ?></code> та
            <code>max_execution_time=<?= e(ini_get('max_execution_time')) ?></code>.
        </p>
    </section>

    <?php if ($imports): ?>
    <section class="card">
        <h2>Останні імпорти</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Файл</th><th>Статус</th><th>Рядків</th><th>Валідних</th><th>З помилками</th><th>Створено</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($imports as $imp): ?>
                <tr class="<?= (int) $imp['id'] === $importId ? 'active' : '' ?>">
                    <td><?= (int) $imp['id'] ?></td>
                    <td><?= e($imp['filename']) ?></td>
                    <td><span class="badge <?= e($imp['status']) ?>"><?= e($statusLabels[$imp['status']]) ?></span>
                        <?php if ($imp['error']): ?><div class="error"><?= e($imp['error']) ?></div><?php endif ?></td>
                    <td><?= number_format((int) $imp['rows_processed'], 0, '', ' ') ?></td>
                    <td><?= number_format((int) $imp['rows_valid'], 0, '', ' ') ?></td>
                    <td><?= number_format((int) $imp['rows_invalid'], 0, '', ' ') ?></td>
                    <td><?= e($imp['created_at']) ?></td>
                    <td>
                        <a href="?import=<?= (int) $imp['id'] ?>">Переглянути</a>
                        <?php if ($imp['status'] === 'processing'): ?>
                            <button type="button" class="link resume" data-id="<?= (int) $imp['id'] ?>">Продовжити</button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card">
        <h2>Заявки імпорту #<?= $importId ?> <small>(<?= number_format($total, 0, '', ' ') ?>)</small></h2>
        <p>
            <a href="?import=<?= $importId ?>" class="<?= $onlyInvalid ? '' : 'current' ?>">Усі</a> ·
            <a href="?import=<?= $importId ?>&invalid=1" class="<?= $onlyInvalid ? 'current' : '' ?>">Лише з помилками</a>
        </p>
        <div class="table-wrap">
        <table class="leads">
            <thead><tr>
                <th>Рядок</th><th>ID</th><th>Створено</th><th>Ім'я</th><th>Телефон</th><th>Email</th><th>Місто</th>
                <th>Джерело</th><th>Продукт</th><th>Бюджет</th><th>Статус</th><th>Менеджер</th><th>Наст. контакт</th><th>Помилки</th>
            </tr></thead>
            <tbody>
            <?php foreach ($leads as $l): ?>
                <tr class="<?= $l['is_valid'] ? '' : 'invalid' ?>">
                    <td><?= (int) $l['line_no'] ?></td>
                    <td><?= e($l['external_id']) ?></td>
                    <td><?= e($l['created_at']) ?></td>
                    <td><?= e(trim($l['first_name'] . ' ' . $l['last_name'])) ?></td>
                    <td><?= e($l['phone'] ?? $l['phone_raw']) ?></td>
                    <td><?= e($l['email'] ?? $l['email_raw']) ?></td>
                    <td><?= e($l['city']) ?></td>
                    <td><?= e($l['source']) ?></td>
                    <td><?= e($l['product']) ?></td>
                    <td><?= $l['budget_uah'] !== null ? number_format((int) $l['budget_uah'], 0, '', ' ') : '' ?></td>
                    <td><?= e($l['status']) ?></td>
                    <td><?= e($l['manager']) ?></td>
                    <td><?= e($l['next_contact_at']) ?></td>
                    <td class="error"><?= e($l['errors']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">← Назад</a><?php endif ?>
            <span>Сторінка <?= $page ?> з <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>">Далі →</a><?php endif ?>
        </nav>
        <?php endif ?>
    </section>
    <?php endif ?>
</main>
<script>window.CHUNK_SIZE = <?= (int) config()['chunk_size'] ?>;</script>
<script src="app.js"></script>
</body>
</html>
