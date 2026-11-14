<?php

declare(strict_types=1);

namespace EquiGov\Core\Support;

final class Time
{
    /**
     * Data GMT do MySQL ("2026-11-13 19:48:17") → ISO 8601 com fuso ("2026-11-13T19:48:17+00:00").
     * mysql_to_rfc3339() do WordPress omite o fuso, e o navegador interpreta como hora local.
     */
    public static function gmtToIso(?string $mysqlGmt): ?string
    {
        if ($mysqlGmt === null || $mysqlGmt === '' || str_starts_with($mysqlGmt, '0000')) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlGmt, new \DateTimeZone('UTC'));
        return $dt ? $dt->format(DATE_ATOM) : null;
    }
}
