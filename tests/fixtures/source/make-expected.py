"""Независими очаквания за извлеците (openpyxl + Python, НЕ parser-ът на плъгина) → expected.json.
Пускане: python3 -I tests/fixtures/source/make-expected.py"""
import json, os, re, hashlib, datetime, openpyxl
here = os.path.dirname(os.path.abspath(__file__))
def serial(v):  # Excel 1900 система → ISO
    return (datetime.date(1899, 12, 30) + datetime.timedelta(days=int(v))).isoformat()
out = {}
for name in ('2026-09-08-part1-excerpt.xlsx', '2026-09-08-part2-excerpt.xlsx'):
    p = os.path.join(here, name)
    ws = openpyxl.load_workbook(p, read_only=True).worksheets[0]
    rows = [list(r) for r in ws.iter_rows(values_only=True)]
    def s(v): return '' if v is None else (v.strftime('%Y-%m-%d') if isinstance(v, datetime.datetime) else str(v))
    a_col = [s(r[0]).replace(' ', '') for r in rows]
    prefixed = [a for a in a_col if re.match(r'^[ПТPT]\d{7,}', a)]
    unprefixed_numeric = [a for a in a_col if re.match(r'^\d{7,}$', a)]
    by_reg = {}
    for r in rows:
        a = s(r[0]).replace(' ', '')
        if re.match(r'^[ПТPT]\d{7,}', a): by_reg.setdefault(a, r)
    cells = {}
    for reg, r in by_reg.items():
        raw = s(r[3])
        m = re.search(r'(\d{1,2})[.,/](\d{1,2})[.,/](\d{4})', raw)  # първата пълна дата в клетката (независимо от parser-а)
        first = '%04d-%02d-%02d' % (int(m.group(3)), int(m.group(2)), int(m.group(1))) if m else (raw if re.match(r'^\d{4}-\d{2}-\d{2}$', raw) else None)
        # name: вътрешните интервали се свиват до един — документирана нормализация на плъгина (babh6_normalize_row), не загуба на данни
        cells[reg] = {'notif_raw': raw, 'notif_first_iso': first, 'name': ' '.join(s(r[9]).split()), 'composition_len': len(re.sub(r'\s+', ' ', s(r[11]).strip())), 'storage_len': len(s(r[8]).strip()), 'launch_raw': s(r[12])}
    out[name] = {
        'sha256': hashlib.sha256(open(p, 'rb').read()).hexdigest(),
        'physical_rows': len(rows),
        'rows_with_prefixed_reg': len(prefixed), 'distinct_prefixed_reg': len(set(prefixed)),
        'rows_with_unprefixed_numeric_A': len(unprefixed_numeric),
        'anomalies': {k: v for k, v in cells.items() if k in ('Т2225069041', 'Т222607841***', 'П222607844', 'Т242607878', 'Т2225068975', 'Т222614163', 'Т220800536', 'Т221100099', 'Т222304713')},
        'serial_46357': serial(46357), 'serial_46366': serial(46366),
    }
json.dump(out, open(os.path.join(here, 'expected.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(json.dumps(out, ensure_ascii=False, indent=1))
