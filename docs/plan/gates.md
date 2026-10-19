<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# Критерии за пускане

Нито един gate в този пакет е изпълнен. Това са чеклисти за бъдеща реализация. За числовите цели и severity виж master-spec.md, раздел 12.


## CORE — QA-010

- [ ] ARC-001 потвърждава текущия commit и проверката на историческите рискове.
- [ ] QA-002: реалният corpus/holdout, raw provenance, row accounting и data quality са приети.
- [ ] QA-003: failed/partial import не променя активния набор; concurrency/retry/rollback са проверени.
- [ ] QA-004/005/006: E2E, реални mobile devices, всички критични states и accessibility са приети.
- [ ] QA-007: admin review, corrections, merge/split, audit и права са приети.
- [ ] QA-008/009: migration/restore и измерен performance budget са приети.
- [ ] SEC-011: няма отворен Sev0/1/2 в core scope.
- [ ] Неприети premium/AI функции са изключени server-side и ясно означени.
- [ ] OPS-007: готов конкретен release artifact, rollback и оперативен owner.


## PRO скелет — QA-011

- [ ] Org membership, role, feature availability, entitlement и quota са отделни, server-enforced проверки.
- [ ] Нов feature key се добавя чрез catalog без разпръснати is_pro условия.
- [ ] FREE→grant→expiry работи с pending jobs, cache и direct download URLs.
- [ ] MON-012: watch baseline, idempotency, unsubscribe и tenant isolation са приети.
- [ ] AI проверките имат evidence, context, quotas и измерени unsupported-claim regressions.
- [ ] Saved queries, team/workspace и сравненията използват общите contracts.


## Платено пускане — QA-012/013

- [ ] Всички CORE/PRO условия остават изпълнени върху един release candidate.
- [ ] BIL-016: sandbox lifecycle и всички откази/повторения минават.
- [ ] BIL-013: пропуснат webhook се открива и коригира чрез reconciliation.
- [ ] Цени, валути, taxes, invoices, trial/grace/refund/seat политики са одобрени.
- [ ] SEC-010 и OPS-013 са приети; има support и incident owner.
- [ ] QA-013 пилотно приемане с представител на производител и търговец е завършено.
- [ ] Има конкретен live rollout plan за финално одобрение; самият план не прави реално плащане.


## След пускане — QA-014

- [ ] Първи реален BABH пакет и първи renewal са проследени от край до край.
- [ ] 7/30-дневни review резултати с реални metrics, incidents и нови regressions.
- [ ] SLO/backup/reconciliation alerts работят и имат owner.
