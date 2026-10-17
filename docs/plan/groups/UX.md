<!-- Генерирано от docs/plan/source/proveri-babh-full-plan-bg-v1.0.html (sha256 7d78033aea6368252c3e1d6dbb4af70699799d7a94c886416184d70cfb6c3c5d) чрез docs/plan/tools/build_backlog.py. Не редактирай на ръка. -->

# UX · Дизайн система, мобилен интерфейс и достъпност

**Отговорност:** UI / frontend инженер  
**Обхват:** Общи компоненти, layout, router и client state.

| ID | Задача | Пр. | Етап | Вълна | Статус | Зависимости | Резултат |
|---|---|---|---|---|---|---|---|
| UX-001 | Дизайн tokens и четима кирилица | P1 | R2 | 4 | planned | ARC-006 | design tokens и component inventory |
| UX-002 | Responsive shell | P1 | R2 | 5 | planned | UX-001 | app shell |
| UX-003 | Router и възстановим URL | P1 | R2 | 6 | planned | UX-002 | router и URL state schema |
| UX-004 | Управление на асинхронни заявки | P1 | R2 | 15 | planned | UX-003, API-003 | query state hooks/services |
| UX-005 | Бутони, форми и системни състояния | P1 | R2 | 5 | planned | UX-001 | component library |
| UX-006 | Диалози, drawer и mobile filters | P1 | R2 | 6 | planned | UX-002, UX-005 | Dialog/Drawer/FilterSheet |
| UX-007 | Семантика и клавиатурна работа | P1 | R2 | 6 | planned | UX-005 | accessibility behavior contract |
| UX-008 | Дълги стойности и адаптивни детайли | P1 | R2 | 6 | planned | UX-001, UX-005 | text/display primitives |
| UX-009 | Един компонент за таблици и карти | P1 | R2 | 6 | planned | UX-001, UX-005 | DataList component |
| UX-010 | Надеждни графики | P1 | R2 | 6 | planned | UX-005 | ChartFrame и chart adapters |
| UX-011 | Контраст, reflow и намалено движение | P1 | R2 | 7 | planned | UX-007, UX-008 | a11y tokens и styles |
| UX-012 | Изолиран CSS и WordPress интеграция | P1 | R2 | 7 | planned | UX-002, UX-003 | scoped assets и integration adapters |

## UX-001 · Дизайн tokens и четима кирилица

Уеднакви типография, spacing, цвят, граници и плътност; ограничи hero височините и оранжевото до действия/акценти.

- **Приоритет / етап / вълна:** P1 / R2 / 4
- **Резултат:** design tokens и component inventory
- **Зависимости:** ARC-006
- **Основание:** UX-11, LIVE-13 · покрива: LIVE-13, UX-11
- **Статус:** planned

**Приемане**

- Текстовете имат четима йерархия и контраст
- Еднакви елементи ползват един token

**Конкретна проверка:** Преглед на дълги български имена на desktop и 320px.

## UX-002 · Responsive shell

Направи устойчив sidebar/header на desktop и едно мобилно меню; safe areas и viewport height не скриват съдържание.

- **Приоритет / етап / вълна:** P1 / R2 / 5
- **Резултат:** app shell
- **Зависимости:** UX-001
- **Основание:** UX-01, UX-02, UX-05 · покрива: UX-01, UX-02, UX-05
- **Статус:** planned

**Приемане**

- Няма хоризонтален overflow на страницата при 320px
- Навигацията не закрива последния ред или action

**Конкретна проверка:** 320, 390, 768, 900, 1024 и 1440px с отворена клавиатура.

## UX-003 · Router и възстановим URL

Адресът носи query, filters, sort, detail и profile tab; старите #p/#f връзки се поддържат чрез mapping.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** router и URL state schema
- **Зависимости:** UX-002
- **Основание:** PR-10, CO-08, LIVE-07 · покрива: LIVE-07, PR-10, CO-08
- **Статус:** planned

**Приемане**

- Back/Forward/reload възстановяват правилния екран
- Ново търсене премахва стария product deep link

**Конкретна проверка:** Отвори #p, търси друго, reload: старият продукт не се появява.

## UX-004 · Управление на асинхронни заявки

Комбинирай AbortController, request identity и debounce cleanup; при навигация ресурсите се изолират.

- **Приоритет / етап / вълна:** P1 / R2 / 15
- **Резултат:** query state hooks/services
- **Зависимости:** UX-003, API-003
- **Основание:** PR-01, PR-02, CO-01 · покрива: PR-01, PR-02, CO-01
- **Статус:** planned

**Приемане**

- Само отговорът за текущия request обновява view
- Clear отменя чакащ debounce и loading state

**Конкретна проверка:** Бавна A, бърза B; A пристига последна; Clear преди debounce.

## UX-005 · Бутони, форми и системни състояния

Създай общи Button/Input/Select/Notice/Skeleton/Empty/Error; иконите имат фиксиран box.

- **Приоритет / етап / вълна:** P1 / R2 / 5
- **Резултат:** component library
- **Зависимости:** UX-001
- **Основание:** UX-03, UX-04 · покрива: UX-03, UX-04
- **Статус:** planned

**Приемане**

- Disabled/loading/error са различими и достъпни
- SVG и inherited CSS не увеличават контролите

**Конкретна проверка:** Проверка на бутона Провери и waitlist form с дълга грешка.

## UX-006 · Диалози, drawer и mobile filters

Осигури focus trap, Escape, inert фон, restore focus и cleanup на scroll lock; не rerender-вай целия filter tree.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** Dialog/Drawer/FilterSheet
- **Зависимости:** UX-002, UX-005
- **Основание:** UX-06, UX-07 · покрива: UX-06, UX-07
- **Статус:** planned

**Приемане**

- Затваряне по всички пътища връща фокуса
- Apply/Cancel имат ясна семантика за черновата на филтрите

**Конкретна проверка:** Затвори чрез Escape, route change и network error.

## UX-007 · Семантика и клавиатурна работа

Използвай link за навигация, button за действие, labels, headings и live regions; screen reader съобщава резултата.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** accessibility behavior contract
- **Зависимости:** UX-005
- **Основание:** UX-08 · покрива: UX-08
- **Статус:** planned

**Приемане**

- Всички действия работят без мишка
- Фокусът е видим и не се скрива под sticky header

**Конкретна проверка:** Клавиатурно търсене, филтър, детайл, експорт и връщане.

## UX-008 · Дълги стойности и адаптивни детайли

Правило за line clamp само в списък; пълно име в детайл, controlled wrapping и copy с feedback.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** text/display primitives
- **Зависимости:** UX-001, UX-005
- **Основание:** UX-09, UX-10 · покрива: UX-09, UX-10
- **Статус:** planned

**Приемане**

- ID, URL и фирма не разбиват layout
- Няма огромна празна зона около един бутон

**Конкретна проверка:** 300-символно име, 20k състав, 200% размер на текста.

## UX-009 · Един компонент за таблици и карти

Общ data model със sort, selection, pagination, column visibility и mobile priorities; запази view preference.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** DataList component
- **Зависимости:** UX-001, UX-005
- **Основание:** LIVE-12 · покрива: LIVE-12
- **Статус:** planned

**Приемане**

- Desktop table и mobile cards имат идентични IDs/counts
- Скритите колони остават достъпни в детайл

**Конкретна проверка:** Смени breakpoint и view без загуба на query или selection.

## UX-010 · Надеждни графики

Ограничи размерите, правилни мащаби, нулеви стойности, keyboard tooltip и достъпна таблица.

- **Приоритет / етап / вълна:** P1 / R2 / 6
- **Резултат:** ChartFrame и chart adapters
- **Зависимости:** UX-005
- **Основание:** OV-01, OV-04 · покрива: OV-01, OV-04
- **Статус:** planned

**Приемане**

- Графиката не се разтяга според съседна секция
- Графична дължина съответства на числата

**Конкретна проверка:** 0, 1, 1000, равни стойности и един месец.

## UX-011 · Контраст, reflow и намалено движение

Покрий WCAG 2.2 AA target, prefers-reduced-motion, 400% reflow и touch target product goal 44px.

- **Приоритет / етап / вълна:** P1 / R2 / 7
- **Резултат:** a11y tokens и styles
- **Зависимости:** UX-007, UX-008
- **Основание:** UX-12 · покрива: UX-12
- **Статус:** planned

**Приемане**

- Няма цветово единствено означаване на статус
- Zoom и reduced motion не премахват функционалност

**Конкретна проверка:** Клавиатура, 400% zoom, high contrast и touch на iOS.

## UX-012 · Изолиран CSS и WordPress интеграция

Премахни натрупани overrides; namespace styles и IDs; използвай нормалния WP lifecycle без чужд page takeover.

- **Приоритет / етап / вълна:** P1 / R2 / 7
- **Резултат:** scoped assets и integration adapters
- **Зависимости:** UX-002, UX-003
- **Основание:** UX-12, AD-06 · покрива: UX-12, AD-06
- **Статус:** planned

**Приемане**

- Два shortcode instance не споделят случайно IDs/state
- Плъгинът не счупва theme header/footer

**Конкретна проверка:** Вгради в две теми и в страница с два instance.
