<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
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
</main>
<script>window.CHUNK_SIZE = <?= (int) config()['chunk_size'] ?>;</script>
<script src="app.js"></script>
</body>
</html>
