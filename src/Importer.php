<?php

declare(strict_types=1);

/**
 * Приймає файл шматками та імпортує його покроково.
 *
 * Кожен виклик step() обробляє обмежену кількість рядків (або обмежений час),
 * починаючи з byte_offset, збереженого в imports. Вставка рядків і зсув offset
 * відбуваються в одній транзакції, тому крок атомарний: після обриву його можна
 * просто повторити, а UNIQUE(import_id, line_no) + INSERT IGNORE гарантує відсутність дублів.
 */
final class Importer
{
    public function __construct(private PDO $db, private array $config)
    {
    }

    public function filePath(int $importId): string
    {
        return $this->config['upload_dir'] . '/' . $importId . '.csv';
    }

    public function find(int $importId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM imports WHERE id = ?');
        $stmt->execute([$importId]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $filename, int $size): int
    {
        $stmt = $this->db->prepare('INSERT INTO imports (filename, file_size) VALUES (?, ?)');
        $stmt->execute([mb_substr($filename, 0, 255), $size]);
        $id = (int) $this->db->lastInsertId();
        file_put_contents($this->filePath($id), '');
        return $id;
    }

    /** Дописує шматок файлу з позиції $offset (повторна відправка того ж шматка безпечна). */
    public function appendChunk(int $importId, int $offset, string $tmpFile): array
    {
        $import = $this->find($importId);
        if (!$import || $import['status'] !== 'uploading') {
            throw new RuntimeException('Імпорт не знайдено або завантаження вже завершено');
        }
        $path = $this->filePath($importId);
        clearstatcache(true, $path);
        $current = is_file($path) ? filesize($path) : 0;
        if ($offset > $current) {
            throw new RuntimeException("Пропущено шматок: очікувався offset $current, отримано $offset");
        }

        $fp = fopen($path, 'c');
        flock($fp, LOCK_EX);
        ftruncate($fp, $offset);
        fseek($fp, $offset);
        $in = fopen($tmpFile, 'rb');
        stream_copy_to_stream($in, $fp);
        fclose($in);
        fflush($fp);
        $uploaded = ftell($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($uploaded > (int) $import['file_size']) {
            throw new RuntimeException('Отримано більше даних, ніж заявлений розмір файлу');
        }
        $status = $uploaded === (int) $import['file_size'] ? 'processing' : 'uploading';
        $this->db->prepare('UPDATE imports SET uploaded_bytes = ?, status = ? WHERE id = ?')
            ->execute([$uploaded, $status, $importId]);

        return ['uploaded_bytes' => $uploaded, 'status' => $status];
    }

    public function step(int $importId): array
    {
        $started = microtime(true);
        $db = $this->db;
        $db->beginTransaction();
        try {
            // Блокуємо запис імпорту, щоб два паралельні кроки не обробили ті самі рядки.
            $stmt = $db->prepare('SELECT * FROM imports WHERE id = ? FOR UPDATE');
            $stmt->execute([$importId]);
            $import = $stmt->fetch();
            if (!$import) {
                throw new RuntimeException('Імпорт не знайдено');
            }
            if ($import['status'] !== 'processing') {
                $db->commit();
                return $this->progress($import, 0, $started);
            }

            $fp = fopen($this->filePath($importId), 'rb');
            if ($fp === false) {
                throw new RuntimeException('Файл імпорту не знайдено');
            }
            $header = fgetcsv($fp, null, ',', '"', '');
            $normalizer = new LeadNormalizer($header ?: []);
            $offset = (int) $import['byte_offset'];
            if ($offset > 0) {
                fseek($fp, $offset);
            }

            $lineNo = (int) $import['rows_processed'];
            $valid = $invalid = $stepRows = 0;
            $batch = [];
            $maxRows = $this->config['step_max_rows'];
            $maxSeconds = $this->config['step_max_seconds'];
            $batchSize = $this->config['insert_batch'];

            while ($stepRows < $maxRows && ($row = fgetcsv($fp, null, ',', '"', '')) !== false) {
                if ($row === [null]) { // порожній рядок
                    continue;
                }
                $lineNo++;
                $stepRows++;
                $values = $normalizer->normalize($row, $importId, $lineNo);
                $values[19] ? $valid++ : $invalid++;
                $batch[] = $values;

                if (count($batch) >= $batchSize) {
                    $this->insert($batch);
                    $batch = [];
                    if (microtime(true) - $started > $maxSeconds) {
                        break;
                    }
                }
            }
            if ($batch) {
                $this->insert($batch);
            }

            $newOffset = ftell($fp);
            $eof = feof($fp) || $newOffset >= (int) $import['file_size'];
            fclose($fp);

            $db->prepare(
                'UPDATE imports SET byte_offset = ?, rows_processed = ?, rows_valid = rows_valid + ?,
                    rows_invalid = rows_invalid + ?, status = ?, finished_at = IF(? = "done", NOW(), NULL)
                 WHERE id = ?'
            )->execute([
                $newOffset, $lineNo, $valid, $invalid,
                $eof ? 'done' : 'processing', $eof ? 'done' : 'processing', $importId,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof InvalidArgumentException) { // невірна структура файлу — повтор не допоможе
                $db->prepare('UPDATE imports SET status = "failed", error = ? WHERE id = ?')
                    ->execute([mb_substr($e->getMessage(), 0, 1000), $importId]);
            }
            throw $e;
        }

        return $this->progress($this->find($importId), $stepRows, $started);
    }

    private function insert(array $rows): void
    {
        $columns = LeadNormalizer::DB_COLUMNS;
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT IGNORE INTO leads (' . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $placeholders));
        $this->db->prepare($sql)->execute(array_merge(...$rows));
    }

    private function progress(array $import, int $stepRows, float $started): array
    {
        return [
            'import_id' => (int) $import['id'],
            'status' => $import['status'],
            'file_size' => (int) $import['file_size'],
            'byte_offset' => (int) $import['byte_offset'],
            'rows_processed' => (int) $import['rows_processed'],
            'rows_valid' => (int) $import['rows_valid'],
            'rows_invalid' => (int) $import['rows_invalid'],
            'error' => $import['error'],
            'step_rows' => $stepRows,
            'step_seconds' => round(microtime(true) - $started, 3),
        ];
    }
}
