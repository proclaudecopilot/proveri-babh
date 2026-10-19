#!/usr/bin/env python3
"""Проверява целостта на docs/plan/backlog.json (и status.json, ако има).

Проверки (всяка нарушена = грешка, изход 1):
  - 225 задачи, 19 групи; броят по група съвпада с обявения task_count
  - уникални ID, формат <GROUP>-NNN, групата съществува
  - priority/milestone/status са от допустимите стойности
  - всяка зависимост съществува; няма цикли
  - задача не може да е done, ако зависимост не е done/dropped (предупреждение, не грешка)
  - task с status != planned има status_note (къде е доказателството)
  - полетата summary, deliverable, acceptance (≥1), check са непразни
  - status.json сочи само към съществуващи задачи и допустими статуси

Използване:  python3 -I docs/plan/tools/validate_backlog.py [--waves]
Без външни зависимости.
"""
import json
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
PLAN = os.path.join(ROOT, 'docs', 'plan')


def main():
    b = json.load(open(os.path.join(PLAN, 'backlog.json'), encoding='utf-8'))
    errors, warnings = [], []
    groups = {g['id']: g for g in b['groups']}
    tasks = b['tasks']
    by = {}
    for t in tasks:
        if t['id'] in by:
            errors.append('дублиран ID ' + t['id'])
        by[t['id']] = t
    if len(tasks) != 225:
        errors.append('очаквани 225 задачи, намерени %d' % len(tasks))
    if len(groups) != 19:
        errors.append('очаквани 19 групи, намерени %d' % len(groups))
    for gid, g in groups.items():
        n = sum(1 for t in tasks if t['group'] == gid)
        if n != g['task_count']:
            errors.append('група %s: обявени %d задачи, намерени %d' % (gid, g['task_count'], n))
    for t in tasks:
        tid = t['id']
        if not re.match(r'^[A-Z]{2,4}-\d{3}$', tid):
            errors.append('невалиден формат на ID ' + tid)
        if t['group'] not in groups or not tid.startswith(t['group'] + '-'):
            errors.append(tid + ': групата не съответства на ID')
        if t['priority'] not in b['priorities']:
            errors.append(tid + ': невалиден priority ' + t['priority'])
        if t['milestone'] not in b['milestones']:
            errors.append(tid + ': невалиден milestone ' + t['milestone'])
        if t['status'] not in b['statuses']:
            errors.append(tid + ': невалиден status ' + t['status'])
        if t['status'] != 'planned' and not t.get('status_note'):
            errors.append(tid + ': статус ' + t['status'] + ' без status_note (къде е доказателството?)')
        for f in ('summary', 'deliverable', 'check'):
            if not t.get(f):
                errors.append(tid + ': празно поле ' + f)
        if not t.get('acceptance'):
            errors.append(tid + ': без критерии за приемане')
        for d in t['depends_on']:
            if d not in by:
                errors.append(tid + ': непозната зависимост ' + d)
            elif d == tid:
                errors.append(tid + ': зависи от себе си')
        if t['status'] == 'done':
            for d in t['depends_on']:
                if d in by and by[d]['status'] not in ('done', 'dropped'):
                    warnings.append(tid + ' е done, но зависимостта ' + d + ' е ' + by[d]['status'])
    # цикли
    state = {}

    def visit(tid, stack):
        if state.get(tid) == 2:
            return
        if state.get(tid) == 1:
            errors.append('цикъл: ' + ' -> '.join(stack + [tid]))
            return
        state[tid] = 1
        for d in by[tid]['depends_on']:
            if d in by:
                visit(d, stack + [tid])
        state[tid] = 2
    for t in tasks:
        visit(t['id'], [])

    sf = os.path.join(PLAN, 'status.json')
    if os.path.exists(sf):
        st = json.load(open(sf, encoding='utf-8'))
        for tid, v in st.items():
            if tid not in by:
                errors.append('status.json: непозната задача ' + tid)
            if v.get('status') not in b['statuses']:
                errors.append('status.json: невалиден статус за ' + tid)
            if v.get('status') != 'planned' and not v.get('note'):
                errors.append('status.json: ' + tid + ' без note')
            if by.get(tid, {}).get('status') != v.get('status'):
                errors.append('status.json и backlog.json се разминават за ' + tid + ' — пусни build_backlog.py')

    counts = {}
    for t in tasks:
        counts[t['status']] = counts.get(t['status'], 0) + 1
    print('задачи:', len(tasks), '· групи:', len(groups), '· статуси:', ', '.join('%s=%d' % kv for kv in sorted(counts.items())))
    if '--waves' in sys.argv:
        waves = {}
        for t in tasks:
            waves.setdefault(t['wave'], []).append(t['id'])
        for w in sorted(waves):
            print('вълна %d (%d): %s' % (w, len(waves[w]), ' '.join(waves[w])))
    for w in warnings:
        print('ПРЕДУПРЕЖДЕНИЕ:', w)
    for e in errors:
        print('ГРЕШКА:', e)
    if errors:
        sys.exit(1)
    print('OK')


if __name__ == '__main__':
    main()
