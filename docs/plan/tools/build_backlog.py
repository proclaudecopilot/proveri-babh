#!/usr/bin/env python3
"""Генерира структурирания backlog от оригиналното HTML задание.

Вход:  docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (единственият източник на истината за
       текста на задачите; не се редактира на ръка).
Изход: docs/plan/backlog.json, docs/plan/evidence.json, docs/plan/master-spec.md, docs/plan/gates.md,
       docs/plan/groups/<GROUP>.md, docs/plan/waves.md

Статусите на задачите НЕ идват от HTML-а: те се пазят в docs/plan/status.json и се сливат при
генериране, за да може заданието да се регенерира, без да се губи напредъкът.

Използване:  python3 -I docs/plan/tools/build_backlog.py
Без външни зависимости (само стандартната библиотека).
"""
import hashlib
import html
import json
import os
import re
import sys
from collections import OrderedDict

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
PLAN = os.path.join(ROOT, 'docs', 'plan')
SRC = os.path.join(PLAN, 'source', 'proveri-babh-full-plan-bg-v1.0.html')
STATUS_FILE = os.path.join(PLAN, 'status.json')

MILESTONES = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5']
PRIORITIES = ['P0', 'P1']
STATUSES = ['planned', 'in_progress', 'blocked', 'in_review', 'done', 'dropped']


def inline(s):
    s = re.sub(r'<code>(.*?)</code>', r'`\1`', s, flags=re.S)
    s = re.sub(r'<a [^>]*href="(https?://[^"]+)"[^>]*>(.*?)</a>', r'[\2](\1)', s, flags=re.S)
    s = re.sub(r'<(b|strong)>(.*?)</\1>', r'**\2**', s, flags=re.S)
    s = re.sub(r'<(i|em)>(.*?)</\1>', r'*\2*', s, flags=re.S)
    s = re.sub(r'<[^>]+>', '', s)
    return html.unescape(re.sub(r'\s+', ' ', s)).strip()


def plain(s):
    return html.unescape(re.sub(r'<[^>]+>', '', s)).strip()


def block_markdown(sec):
    """HTML фрагмент (h1-h4, p, table, ul, ol) → markdown."""
    out = []
    tok = re.compile(r'<h([1-4])[^>]*>(.*?)</h\1>|<p[^>]*>(.*?)</p>|<table>(.*?)</table>|<ul>(.*?)</ul>|<ol>(.*?)</ol>', re.S)
    for m in tok.finditer(sec):
        if m.group(1):
            out.append('\n' + '#' * int(m.group(1)) + ' ' + inline(m.group(2)) + '\n')
        elif m.group(3) is not None:
            t = inline(m.group(3))
            if t:
                out.append(t + '\n')
        elif m.group(4) is not None:
            rows = re.findall(r'<tr>(.*?)</tr>', m.group(4), flags=re.S)
            md = []
            for i, r in enumerate(rows):
                cells = [inline(c).replace('|', '\\|') for c in re.findall(r'<t[hd][^>]*>(.*?)</t[hd]>', r, flags=re.S)]
                md.append('| ' + ' | '.join(cells) + ' |')
                if i == 0:
                    md.append('|' + '---|' * len(cells))
            out.append('\n'.join(md) + '\n')
        else:
            items = re.findall(r'<li>(.*?)</li>', m.group(5) or m.group(6), flags=re.S)
            pre = '- ' if m.group(5) is not None else '1. '
            out.append('\n'.join(pre + inline(x) for x in items) + '\n')
    return '\n'.join(out)


def parse_tasks(src):
    groups = []
    for gm in re.finditer(r'<section class="task-group" id="group-(\w+)">(.*?)</section>', src, flags=re.S):
        gid, body = gm.group(1), gm.group(2)
        h = re.search(r'<h3>(.*?)<span class="badge">(.*?)</span></h3>', body, flags=re.S)
        note = re.search(r'<p class="group-note">(.*?)</p>', body, flags=re.S)
        title = plain(h.group(1)).rstrip(' ·').strip()
        owner, _, desc = plain(note.group(1)).partition(' · ')
        tasks = []
        for tm in re.finditer(r'<details class="task" id="([\w-]+)" data-priority="(\w+)" data-milestone="(\w+)">(.*?)</details>', body, flags=re.S):
            tid, pri, ms, tb = tm.groups()
            ttitle = plain(re.search(r'<span class="task-title">(.*?)</span>', tb).group(1))
            m = re.search(r'<div class="task-body"><p>(.*?)</p><dl>(.*?)</dl>\s*<h4>Приемане</h4><ul>(.*?)</ul>\s*'
                          r'<h4>Конкретна проверка</h4><p>(.*?)</p><p class="small">Основание: (.*?)</p>', tb, flags=re.S)
            if not m:
                sys.exit('Неразпознат формат на задача ' + tid)
            summary, dl, acc, check, ev = m.groups()
            dd = dict(re.findall(r'<dt>(.*?)</dt><dd>(.*?)</dd>', dl, flags=re.S))
            deps = re.findall(r'class="chip dep">([\w-]+)</a>', dd.get('Зависимости', ''))
            tasks.append(OrderedDict([
                ('id', tid), ('group', gid), ('title', ttitle), ('priority', pri), ('milestone', ms),
                ('summary', plain(summary)),
                ('deliverable', plain(dd.get('Резултат', ''))),
                ('owner', plain(dd.get('Отговорност', ''))),
                ('depends_on', deps),
                ('depends_note', '' if deps else plain(dd.get('Зависимости', ''))),
                ('acceptance', [plain(x) for x in re.findall(r'<li>(.*?)</li>', acc, flags=re.S)]),
                ('check', plain(check)),
                ('evidence', re.findall(r'class="chip">([\w-]+)</a>', ev)),
            ]))
        groups.append(OrderedDict([
            ('id', gid), ('title', title.split(' · ', 1)[1] if ' · ' in title else title),
            ('owner', owner), ('description', desc),
            ('task_count', int(re.search(r'\d+', plain(h.group(2))).group())), ('tasks', tasks)]))
    return groups


def parse_evidence(src):
    ev = OrderedDict()
    for em in re.finditer(r'<article class="evidence" id="evidence-([\w-]+)"><h4>(.*?)</h4><p>(.*?)</p><p class="small">Задачи: (.*?)</p></article>', src, flags=re.S):
        eid, title, text, tasks = em.groups()
        ev[eid] = OrderedDict([('id', eid), ('kind', 'DESIGN' if eid == 'DESIGN' else 'LIVE'),
                               ('title', plain(title).split(' · ', 1)[-1]), ('text', plain(text)),
                               ('tasks', re.findall(r'[A-Z]+-\d+', plain(tasks)))])
    hist = []
    sec = src[src.find('Всички 90 исторически констатации'):]
    for rm in re.finditer(r'<tr id="evidence-([\w-]+)"><td>[\w-]+</td><td>(.*?)</td><td>(.*?)</td></tr>', sec, flags=re.S):
        old, desc, tk = rm.groups()
        d = plain(desc)
        pr, _, d2 = d.partition(' · ')
        hist.append(OrderedDict([('id', old), ('kind', 'HIST'), ('priority', pr), ('finding', d2),
                                 ('tasks', re.findall(r'[A-Z]+-\d+', plain(tk)))]))
    return ev, hist


def topo_waves(tasks):
    by = {t['id']: t for t in tasks}
    level = {}

    def lv(tid, stack=()):
        if tid in level:
            return level[tid]
        if tid in stack:
            sys.exit('Цикъл в зависимостите: ' + ' -> '.join(stack + (tid,)))
        deps = by[tid]['depends_on']
        level[tid] = 0 if not deps else 1 + max(lv(d, stack + (tid,)) for d in deps)
        return level[tid]
    for t in tasks:
        lv(t['id'])
    waves = OrderedDict()
    for t in tasks:
        waves.setdefault(level[t['id']], []).append(t['id'])
    return waves, level


def main():
    src = open(SRC, encoding='utf-8').read()
    sha = hashlib.sha256(open(SRC, 'rb').read()).hexdigest()
    groups = parse_tasks(src)
    evidence, hist = parse_evidence(src)
    status = {}
    if os.path.exists(STATUS_FILE):
        status = json.load(open(STATUS_FILE, encoding='utf-8'))

    tasks = []
    for g in groups:
        for t in g['tasks']:
            st = status.get(t['id'], {})
            t['status'] = st.get('status', 'planned')
            if st.get('note'):
                t['status_note'] = st['note']
            if st.get('artifacts'):
                t['artifacts'] = st['artifacts']
            tasks.append(t)
    ids = {t['id'] for t in tasks}
    for t in tasks:
        for d in t['depends_on']:
            if d not in ids:
                sys.exit('Непозната зависимост ' + d + ' в ' + t['id'])
    waves, level = topo_waves(tasks)

    # обратни връзки: кои констатации покрива всяка задача
    covers = {}
    for e in list(evidence.values()) + hist:
        for tid in e['tasks']:
            covers.setdefault(tid, []).append(e['id'])
    for t in tasks:
        t['covers'] = covers.get(t['id'], [])
        t['wave'] = level[t['id']]

    backlog = OrderedDict([
        ('schema', 'docs/plan/backlog.schema.json'),
        ('spec_version', '1.0'),
        ('spec_date', '2026-10-17'),
        ('generated_from', OrderedDict([('file', os.path.relpath(SRC, ROOT)), ('sha256', sha)])),
        ('milestones', MILESTONES), ('priorities', PRIORITIES), ('statuses', STATUSES),
        ('groups', [OrderedDict([(k, v) for k, v in g.items() if k != 'tasks']) for g in groups]),
        ('tasks', tasks),
    ])
    json.dump(backlog, open(os.path.join(PLAN, 'backlog.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump(OrderedDict([('generated_from', backlog['generated_from']), ('evidence', list(evidence.values())),
                           ('historical_findings', hist)]),
              open(os.path.join(PLAN, 'evidence.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

    # master spec
    a = src.find('<h1 id="specification"')
    b = src.find('id="backlog"')
    spec = block_markdown(src[a:b])
    head = ('<!-- Генерирано от ' + os.path.relpath(SRC, ROOT) + ' (sha256 ' + sha + ') чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->\n')
    open(os.path.join(PLAN, 'master-spec.md'), 'w', encoding='utf-8').write(head + spec)

    # gates
    a = src.find('<section class="section" id="gates">')
    b = src.find('<section class="section" id="evidence">')
    gates = block_markdown(src[a:b]).replace('- [ ]', '- [ ]')
    open(os.path.join(PLAN, 'gates.md'), 'w', encoding='utf-8').write(head + gates)

    # group indexes
    for g in groups:
        lines = [head, '# ' + g['id'] + ' · ' + g['title'], '',
                 '**Отговорност:** ' + g['owner'] + '  ', '**Обхват:** ' + g['description'], '',
                 '| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |', '|---|---|---|---|---|---|---|---|']
        for t in g['tasks']:
            lines.append('| ' + t['id'] + ' | ' + t['title'] + ' | ' + t['priority'] + ' | ' + t['milestone'] + ' | ' + str(t['wave']) +
                         ' | ' + t['status'] + ' | ' + (', '.join(t['depends_on']) or '—') + ' | ' + t['deliverable'] + ' |')
        for t in g['tasks']:
            lines += ['', '## ' + t['id'] + ' · ' + t['title'], '',
                      t['summary'], '',
                      '- **Приоритет / етап / вълна:** ' + t['priority'] + ' / ' + t['milestone'] + ' / ' + str(t['wave']),
                      '- **Резултат:** ' + t['deliverable'],
                      '- **Зависимости:** ' + (', '.join(t['depends_on']) or (t['depends_note'] or '—')),
                      '- **Основание:** ' + ', '.join(t['evidence']) + (' · покрива: ' + ', '.join(t['covers']) if t['covers'] else ''),
                      '- **Статус:** ' + t['status'] + ((' — ' + t['status_note']) if t.get('status_note') else ''),
                      '', '**Приемане**', '']
            lines += ['- ' + x for x in t['acceptance']]
            lines += ['', '**Конкретна проверка:** ' + t['check']]
        open(os.path.join(PLAN, 'groups', g['id'] + '.md'), 'w', encoding='utf-8').write('\n'.join(lines) + '\n')

    # waves
    lines = [head, '# Топологични вълни', '',
             'Вълна N съдържа задачите, чиито зависимости са всички във вълни < N. Задачите в една вълна могат да вървят паралелно '
             '(при спазване на собствеността върху общите договори, виж AGENTS.md). Етапите R0–R5 са release граници; вълните са '
             'редът по зависимости и не ги заменят.', '']
    for w, ids_ in waves.items():
        lines.append('## Вълна ' + str(w) + ' (' + str(len(ids_)) + ' задачи)')
        lines.append('')
        by = {t['id']: t for t in tasks}
        for ms in MILESTONES:
            sel = [i for i in ids_ if by[i]['milestone'] == ms]
            if sel:
                lines.append('- **' + ms + ':** ' + ', '.join(i + ('*' if by[i]['priority'] == 'P0' else '') for i in sel))
        lines.append('')
    lines.append('`*` = P0.')
    open(os.path.join(PLAN, 'waves.md'), 'w', encoding='utf-8').write('\n'.join(lines) + '\n')

    print('tasks', len(tasks), 'groups', len(groups), 'evidence', len(evidence), 'historical', len(hist), 'waves', len(waves))


if __name__ == '__main__':
    main()
