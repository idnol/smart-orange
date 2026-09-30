<?php

declare(strict_types=1);

/**
 * Перетворює рядок CSV на рядок таблиці leads.
 * Рядок не відкидається ніколи: невалідні значення зберігаються як є (phone_raw / email_raw),
 * нормалізоване поле стає NULL, а назва поля потрапляє в errors.
 */
final class LeadNormalizer
{
    public const COLUMNS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone', 'email', 'city', 'source',
        'utm_campaign', 'product', 'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
    ];

    public const DB_COLUMNS = [
        'import_id', 'line_no', 'external_id', 'created_at', 'first_name', 'last_name', 'phone_raw', 'phone',
        'email_raw', 'email', 'city', 'source', 'utm_campaign', 'product', 'budget_uah', 'status', 'manager',
        'comment', 'next_contact_at', 'is_valid', 'errors',
    ];

    private const STATUSES = ['new', 'in_progress', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost', 'spam'];

    /** @var array<string,int> назва колонки => індекс у рядку CSV */
    private array $map;

    public function __construct(array $header)
    {
        $header = array_map(
            fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );
        $missing = array_diff(self::COLUMNS, $header);
        if ($missing) {
            throw new InvalidArgumentException('У файлі немає колонок: ' . implode(', ', $missing));
        }
        $this->map = array_flip($header);
    }

    public function normalize(array $row, int $importId, int $lineNo): array
    {
        $errors = [];
        $get = fn(string $col): ?string => self::nullIfEmpty($row[$this->map[$col]] ?? null);

        if (count($row) < count($this->map)) {
            $errors[] = 'columns';
        }

        $externalId = $get('external_id');
        if ($externalId === null) {
            $errors[] = 'external_id';
        }

        $createdAt = self::dateTime($get('created_at'));
        if ($createdAt === null) {
            $errors[] = 'created_at';
        }

        $firstName = $get('first_name');
        if ($firstName === null) {
            $errors[] = 'first_name';
        }

        $phoneRaw = $get('phone');
        $phone = self::phone($phoneRaw);
        if ($phone === null) {
            $errors[] = 'phone';
        }

        $emailRaw = $get('email');
        $email = $emailRaw !== null && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? mb_strtolower($emailRaw) : null;
        if ($email === null) {
            $errors[] = 'email';
        }

        $budgetRaw = $get('budget_uah');
        $budget = null;
        if ($budgetRaw !== null) {
            $digits = preg_replace('/\D+/u', '', $budgetRaw); // "23 700" з NBSP → 23700
            if ($digits === '' || strlen($digits) > 9) {
                $errors[] = 'budget_uah';
            } else {
                $budget = (int) $digits;
            }
        }

        $status = $get('status');
        if ($status !== null) {
            $status = strtolower($status);
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'status';
            $status = null;
        }

        $nextRaw = $get('next_contact_at');
        $next = self::dateTime($nextRaw);
        if ($nextRaw !== null && $next === null) {
            $errors[] = 'next_contact_at';
        }

        return [
            $importId,
            $lineNo,
            mb_substr($externalId ?? '', 0, 32),
            $createdAt,
            self::cut($firstName, 100),
            self::cut($get('last_name'), 100),
            self::cut($phoneRaw, 50),
            $phone,
            self::cut($emailRaw, 255),
            $email !== null && mb_strlen($email) <= 255 ? $email : null,
            self::cut($get('city'), 100),
            self::cut($get('source'), 100),
            self::cut($get('utm_campaign'), 100),
            self::cut($get('product'), 150),
            $budget,
            $status,
            self::cut($get('manager'), 100),
            self::cut($get('comment'), 1000),
            $next,
            $errors ? 0 : 1,
            $errors ? implode(',', $errors) : null,
        ];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value, " \t\n\r\0\x0B\u{00A0}");
        return $value === '' ? null : $value;
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private static function phone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10 && $digits[0] === '0') { // 0XXXXXXXXX → 380XXXXXXXXX
            $digits = '38' . $digits;
        }
        return preg_match('/^380\d{9}$/', $digits) ? '+' . $digits : null;
    }

    private static function dateTime(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($dt !== false && DateTimeImmutable::getLastErrors() === false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
}
