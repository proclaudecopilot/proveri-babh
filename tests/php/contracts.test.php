<?php
/* Contract тест (ARC-005 конкретна проверка): схемите в contracts/schemas/ отхвърлят невалиден status/enum
   и неочакван тип дата. Валидаторът е минимално подмножество на JSON Schema (type, enum, const, pattern,
   required, properties, items, minimum, maxLength) без външни зависимости — достатъчно за as-is договора 1.0.
   Пускане: php tests/php/contracts.test.php */
require __DIR__ . '/bootstrap.php';

function js_validate($schema, $value, $path = '$') {
    $errs = array();
    if (isset($schema['type'])) {
        $types = (array)$schema['type']; $ok = false;
        foreach ($types as $ty) {
            if ($ty === 'null' && $value === null) $ok = true;
            elseif ($ty === 'string' && is_string($value)) $ok = true;
            elseif ($ty === 'integer' && is_int($value)) $ok = true;
            elseif ($ty === 'number' && (is_int($value) || is_float($value))) $ok = true;
            elseif ($ty === 'boolean' && is_bool($value)) $ok = true;
            elseif ($ty === 'array' && is_array($value) && array_values($value) === $value) $ok = true;
            elseif ($ty === 'object' && is_array($value) && (empty($value) || array_values($value) !== $value)) $ok = true;
        }
        if (!$ok) { $errs[] = "$path: тип " . gettype($value) . " не е " . implode('|', $types); return $errs; }
    }
    if ($value === null) return $errs;
    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) $errs[] = "$path: стойност " . var_export($value, true) . " извън enum";
    if (array_key_exists('const', $schema) && $value !== $schema['const']) $errs[] = "$path: не е const " . var_export($schema['const'], true);
    if (isset($schema['pattern']) && is_string($value) && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value)) $errs[] = "$path: „{$value}“ не отговаря на pattern {$schema['pattern']}";
    if (isset($schema['minimum']) && is_numeric($value) && $value < $schema['minimum']) $errs[] = "$path: под minimum";
    if (isset($schema['maxLength']) && is_string($value) && mb_strlen($value, 'UTF-8') > $schema['maxLength']) $errs[] = "$path: над maxLength";
    if (is_array($value)) {
        foreach ((array)(isset($schema['required']) ? $schema['required'] : array()) as $k) if (!array_key_exists($k, $value)) $errs[] = "$path: липсва задължително поле $k";
        foreach ((array)(isset($schema['properties']) ? $schema['properties'] : array()) as $k => $sub) if (array_key_exists($k, $value)) $errs = array_merge($errs, js_validate($sub, $value[$k], "$path.$k"));
        if (isset($schema['items']) && array_values($value) === $value) foreach ($value as $i => $v) $errs = array_merge($errs, js_validate($schema['items'], $v, "$path[$i]"));
    }
    return $errs;
}

$root = dirname(__DIR__, 2);
$card_schema = json_decode(file_get_contents($root . '/contracts/schemas/product-card.schema.json'), true);
$item_schema = json_decode(file_get_contents($root . '/contracts/schemas/product-item.schema.json'), true);
$err_schema  = json_decode(file_get_contents($root . '/contracts/schemas/error.schema.json'), true);
t_eq('схемите се парсват', true, is_array($card_schema) && is_array($item_schema) && is_array($err_schema));

/* Валидна карта — както babh6_row_to_card я връща */
$card = array('reg' => 'Т162618382', 't' => 'Т', 'n' => 'Магнезий + B6', 'p' => 'Полша', 'pk' => 'country', 'pn' => '',
    'tr' => 'Фирма ЕООД', 'tk' => 'firm', 'tn' => 'фирма', 'to' => 0, 'nd' => '2026-03-01', 'cat' => 'minerals', 'catl' => 'Минерали',
    'fc' => 0, 'x' => 0, 'del' => false, 'short' => 1);
t_eq('валидна карта минава', array(), js_validate($card_schema, $card));

/* Невалиден статус/enum */
$bad = $card; $bad['t'] = 'X';
t_eq('невалиден тип на рег. № (enum) се отхвърля', 1, count(js_validate($card_schema, $bad)));
$bad = $card; $bad['pk'] = 'company';
t_eq('невалиден вид на полето производител се отхвърля', 1, count(js_validate($card_schema, $bad)));
$bad = $card; $bad['x'] = 2;
t_eq('x извън {0,1} се отхвърля', 1, count(js_validate($card_schema, $bad)));

/* Неочакван тип дата */
$bad = $card; $bad['nd'] = '17.04.2027';
t_eq('дата d.m.Y вместо ISO се отхвърля', 1, count(js_validate($card_schema, $bad)));
$bad = $card; $bad['nd'] = 20270417;
t_eq('дата като число се отхвърля', 1, count(js_validate($card_schema, $bad)));
$bad = $card; $bad['nd'] = '0000-00-00';
t_eq('0000-00-00 минава pattern-а (известна дупка: pattern не проверява календар — ARC-005 v2.0 трябва да добави format)', 0, count(js_validate($card_schema, $bad)));
$ok = $card; $ok['nd'] = null;
t_eq('null дата = неизвестно е допустимо', array(), js_validate($card_schema, $ok));

/* Липсващо поле и грешка */
$bad = $card; unset($bad['del']);
t_eq('липсващо задължително поле се отхвърля', 1, count(js_validate($card_schema, $bad)));
$e = array('code' => 'babh6_notfound', 'message' => 'Няма намерен запис.', 'data' => array('status' => 404));
t_eq('валидна грешка минава', array(), js_validate($err_schema, $e));
$e['code'] = 'babh6_whatever';
t_eq('непознат код на грешка се отхвърля', 1, count(js_validate($err_schema, $e)));
$e['code'] = 'babh6_notfound'; $e['data']['status'] = '404';
t_eq('status като низ се отхвърля', 1, count(js_validate($err_schema, $e)));

/* Пълен запис: минимален валиден */
$item = array('reg' => 'П052112345', 't' => 'П', 'y' => 2021, 'o' => 'Стара Загора', 'n' => 'X', 'p' => '', 'pk' => '', 'pn' => '', 'tr' => '', 'tk' => '', 'tn' => '',
    'ti' => 0, 'tin' => '', 'tinn' => '', 'to' => 0, 'c' => null, 'pp' => null, 'st' => null, 'nn' => '', 'nd' => null, 'ld' => null, 'ed' => null,
    'cat' => 'other', 'catl' => 'Друго', 'f' => array(), 'x' => 1, 'xd' => '2026-10-16', 'del' => false, 'dn' => '');
t_eq('минимален пълен запис минава', array(), js_validate($item_schema, $item));
$bad = $item; $bad['y'] = 1968;
t_eq('година 1968 от грешни цифри се отхвърля (трябва да е null)', 1, count(js_validate($item_schema, $bad)));
$bad = $item; $bad['f'] = array(array('term' => 'x'));
t_eq('бележка без field и field_label се отхвърля (две грешки)', 2, count(js_validate($item_schema, $bad)));

exit(t_report(__FILE__) ? 0 : 1);
