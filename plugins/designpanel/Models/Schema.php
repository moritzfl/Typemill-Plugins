<?php

namespace Plugins\designpanel\Models;

use InvalidArgumentException;

final class Schema
{
    public static function fields(array $definitions, string $group = ''): array
    {
        $fields = [];
        foreach ($definitions as $key => $field) {
            if (($field['type'] ?? '') === 'fieldset') {
                $fields = array_merge($fields, self::fields($field['fields'] ?? [], $field['legend'] ?? $group));
            } elseif ($key !== 'customcss' && empty($field['security']) && empty($field['dataset'])
                && in_array($field['type'] ?? '', ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'checkboxlist'], true)) {
                $fields[] = ['key' => $key, 'group' => $group] + $field;
            }
        }
        return $fields;
    }

    public static function validate(array $input, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $input)) { continue; }
            $value = $input[$key];
            $error = false;
            switch ($field['type']) {
                case 'checkbox':
                    $error = !is_bool($value);
                    break;
                case 'checkboxlist':
                    $error = !is_array($value) || !array_is_list($value);
                    if (!$error) {
                        foreach ($value as $option) {
                            if ((!is_string($option) && !is_int($option)) || !array_key_exists($option, $field['options'] ?? [])) { $error = true; break; }
                        }
                    }
                    break;
                case 'select':
                case 'radio':
                    $error = !is_scalar($value) || !array_key_exists($value, $field['options'] ?? []);
                    break;
                case 'number':
                    if ($value === '' || $value === null) { $value = ''; break; }
                    $error = !is_numeric($value) || $value < ($field['min'] ?? 0) || $value > ($field['max'] ?? 10000);
                    $value = $error ? 0 : 0 + $value;
                    break;
                default:
                    $error = !is_string($value) || mb_strlen($value) > ($field['maxlength'] ?? 20000);
                    if (!$error && $value !== '') {
                        if (stripos($key, 'color') !== false) { $error = !preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/iD', $value); }
                        if ($key === 'maxwidth') { $error = !preg_match('/^\d+(\.\d+)?(px|rem|em|%)$/D', $value); }
                        if (preg_match('/(link|target)$/i', $key)) { $error = preg_match('/^(javascript|data|vbscript):/i', trim($value)); }
                    }
            }
            if ($error) { throw new InvalidArgumentException('Check ' . ($field['label'] ?? $key) . '.'); }
            $values[$key] = $value;
        }
        return $values;
    }
}
