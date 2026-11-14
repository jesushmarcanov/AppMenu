<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ValidationException;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $present = array_key_exists($field, $data);
            $value = $present ? $data[$field] : null;
            $nullable = in_array('nullable', $fieldRules, true);
            $required = in_array('required', $fieldRules, true);

            // Un campo opcional ausente no se devuelve: así un PATCH parcial no
            // pisa con null los valores ya guardados (orden, rol, disponibilidad…).
            if (!$present) {
                if ($required && !$nullable) {
                    $errors[$field] = 'Este campo es obligatorio.';
                }
                continue;
            }

            if ($value === null || $value === '') {
                if ($required && !$nullable) {
                    $errors[$field] = 'Este campo es obligatorio.';
                } elseif ($nullable) {
                    $clean[$field] = null;
                }
                continue;
            }

            try {
                $value = self::applyRules($field, $value, $fieldRules);
                $clean[$field] = $value;
            } catch (ValidationException $e) {
                $fieldErrors = $e->fields;
                $errors[$field] = $fieldErrors[$field] ?? 'Valor no válido.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /**
     * @param array<int, string> $rules
     */
    private static function applyRules(string $field, mixed $value, array $rules): mixed
    {
        foreach ($rules as $rule) {
            if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'string':
                    if (!is_scalar($value)) {
                        self::fail($field, 'Debe ser texto.');
                    }
                    $value = trim((string) $value);
                    break;

                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        self::fail($field, 'Debe ser un número entero.');
                    }
                    $value = (int) $value;
                    break;

                case 'numeric':
                    if (!is_numeric($value)) {
                        self::fail($field, 'Debe ser un número.');
                    }
                    break;

                case 'decimal':
                    $decimals = (int) ($parameter ?? 2);
                    if (!is_scalar($value) || !preg_match('/^-?\d+(?:\.\d{1,' . $decimals . '})?$/', (string) $value)) {
                        self::fail($field, 'Debe ser un número con máximo ' . $decimals . ' decimales.');
                    }
                    $value = (string) $value;
                    break;

                case 'min':
                    if (!is_numeric($value) || (float) $value < (float) $parameter) {
                        self::fail($field, 'Debe ser mayor o igual a ' . $parameter . '.');
                    }
                    break;

                case 'max':
                    if (!is_numeric($value) || (float) $value > (float) $parameter) {
                        self::fail($field, 'Debe ser menor o igual a ' . $parameter . '.');
                    }
                    break;

                case 'minlen':
                    if (mb_strlen((string) $value) < (int) $parameter) {
                        self::fail($field, 'Debe tener al menos ' . $parameter . ' caracteres.');
                    }
                    break;

                case 'maxlen':
                    if (mb_strlen((string) $value) > (int) $parameter) {
                        self::fail($field, 'Debe tener máximo ' . $parameter . ' caracteres.');
                    }
                    break;

                case 'in':
                    $options = explode(',', (string) $parameter);
                    if (!in_array((string) $value, $options, true)) {
                        self::fail($field, 'Valor no permitido.');
                    }
                    break;

                case 'email':
                    if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                        self::fail($field, 'Debe ser un correo válido.');
                    }
                    $value = mb_strtolower(trim((string) $value));
                    break;

                case 'url':
                    if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                        self::fail($field, 'Debe ser una URL válida.');
                    }
                    break;

                case 'boolean':
                    if (is_bool($value)) {
                        break;
                    }
                    if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
                        $value = true;
                    } elseif (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
                        $value = false;
                    } else {
                        self::fail($field, 'Debe ser verdadero o falso.');
                    }
                    break;

                case 'array':
                    if (!is_array($value)) {
                        self::fail($field, 'Debe ser una lista.');
                    }
                    break;

                case 'date':
                    $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
                    if (!$date || $date->format('Y-m-d') !== (string) $value) {
                        self::fail($field, 'Debe ser una fecha válida (YYYY-MM-DD).');
                    }
                    break;

                case 'time':
                    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', (string) $value)) {
                        self::fail($field, 'Debe ser una hora válida (HH:MM).');
                    }
                    break;

                case 'slug':
                    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value)) {
                        self::fail($field, 'Solo minúsculas, números y guiones.');
                    }
                    break;

                case 'phone':
                    if (!preg_match('/^\+?[0-9][0-9\s\-().]{6,24}$/', (string) $value)) {
                        self::fail($field, 'Debe ser un teléfono válido.');
                    }
                    break;

                case 'unique':
                    self::assertUnique($field, $value, (string) $parameter);
                    break;

                case 'exists':
                    self::assertExists($field, $value, (string) $parameter);
                    break;

                case 'regex':
                    if (!preg_match((string) $parameter, (string) $value)) {
                        self::fail($field, 'Formato no válido.');
                    }
                    break;

                default:
                    throw new \RuntimeException('Regla de validación desconocida: ' . $name);
            }
        }

        return $value;
    }

    private static function assertUnique(string $field, mixed $value, string $parameter): void
    {
        $parts = explode(',', $parameter);
        if (count($parts) < 2) {
            throw new \RuntimeException('La regla unique requiere tabla y columna.');
        }

        [$table, $column] = $parts;
        $idColumn = $parts[3] ?? 'id';
        $sql = sprintf(
            'SELECT COUNT(*) AS total FROM %s WHERE %s = :value',
            Database::quoteIdentifier($table),
            Database::quoteIdentifier($column),
        );
        $params = ['value' => $value];

        if (isset($parts[2]) && $parts[2] !== '') {
            $sql .= sprintf(' AND %s <> :except_id', Database::quoteIdentifier($idColumn));
            $params['except_id'] = (int) $parts[2];
        }

        $row = Database::fetch($sql, $params);
        if ((int) ($row['total'] ?? 0) > 0) {
            self::fail($field, 'Este valor ya está registrado.');
        }
    }

    private static function assertExists(string $field, mixed $value, string $parameter): void
    {
        $parts = explode(',', $parameter);
        if (count($parts) < 2) {
            throw new \RuntimeException('La regla exists requiere tabla y columna.');
        }

        [$table, $column] = $parts;
        $row = Database::fetch(
            sprintf(
                'SELECT COUNT(*) AS total FROM %s WHERE %s = :value',
                Database::quoteIdentifier($table),
                Database::quoteIdentifier($column),
            ),
            ['value' => $value],
        );

        if ((int) ($row['total'] ?? 0) === 0) {
            self::fail($field, 'El valor seleccionado no existe.');
        }
    }

    private static function fail(string $field, string $message): never
    {
        throw new ValidationException([$field => $message]);
    }
}
