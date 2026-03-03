<?php

declare(strict_types=1);

final class Helpers
{
    /**
     * Extrae un número de forma segura desde una fila (array asociativo) de datos.
     * Soporta formatos europeos/americanos y cadenas con símbolo €.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $columns
     */
    public static function getCleanNumber(array $row, string $colName, array $columns): float
    {
        if (!in_array($colName, $columns, true)) {
            return 0.0;
        }

        if (!array_key_exists($colName, $row)) {
            return 0.0;
        }

        $val = $row[$colName];
        $sVal = trim((string)$val);

        if ($sVal === '' || mb_strtolower($sVal) === 'nan') {
            return 0.0;
        }

        if (is_int($val) || is_float($val)) {
            return (float)$val;
        }

        try {
            $sVal = str_replace('€', '', $sVal);
            $sVal = trim($sVal);

            if (str_contains($sVal, ',') && str_contains($sVal, '.')) {
                if (strpos($sVal, ',') < strpos($sVal, '.')) {
                    $sVal = str_replace(',', '', $sVal);
                } else {
                    $sVal = str_replace('.', '', $sVal);
                    $sVal = str_replace(',', '.', $sVal);
                }
            } elseif (str_contains($sVal, ',')) {
                $sVal = str_replace(',', '.', $sVal);
            }

            return (float)$sVal;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Normaliza nombres de columnas de un Excel (trim + cast a string).
     *
     * @param iterable<mixed> $columns
     * @return array<int, string>
     */
    public static function normalizeExcelColumns(iterable $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[] = trim((string)$c);
        }
        return $out;
    }

    /**
     * Convierte un valor numérico a formato español (punto miles, coma decimal).
     */
    public static function fmtNum(mixed $valor): string
    {
        if ($valor === null || trim((string)$valor) === '') {
            return '0,00';
        }

        try {
            $valFloat = (float)$valor;
            $formatted = number_format($valFloat, 2, ',', '.');
            return $formatted;
        } catch (\Throwable) {
            return '0,00';
        }
    }

    /**
     * Convierte fecha (ISO o DateTimeInterface) a formato europeo DD/MM/YYYY.
     */
    public static function fmtDate(null|string|\DateTimeInterface $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        try {
            if ($valor instanceof \DateTimeInterface) {
                return $valor->format('d/m/Y');
            }

            // $valor es string
            $clean = explode('T', $valor)[0];
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $clean);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('d/m/Y');
            }

            return (string)$valor;
        } catch (\Throwable) {
            return (string)$valor;
        }
    }
}

