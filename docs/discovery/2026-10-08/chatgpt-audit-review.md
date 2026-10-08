# Критический разбор исходного ChatGPT-аудита

Дата: 2026-10-08. Статус: исследовательский разбор, не ADR, не принятая архитектура и не приемка работающего Commerz. Метод: чтение четырех исходных файлов, воспроизведение изолированного Python-скрипта, проверка границ выводов и проектирование опровергающих сценариев. Применен `quality:artifact-review`; его рекомендации не становятся требованиями проекта.

## Вывод для проектирования

Исходный материал полезен как тщательно ограниченная гипотеза каталога, свойств, запасов, цен и интеграций. Он хорошо различает товар, продаваемый вариант и физический ресурс; не путает поисковую проекцию с источником остатка; отдельно рассматривает общий склад, наследование свойств и причинность импорта. Его следует сохранить как один из исследовательских входов.

Материал **не описывает полную электронную коммерцию из текущего брифа**: корзины, заказы, купоны, жизненный цикл платежа и возвраты денег не спроектированы. В частности, персональные купоны и корзинные скидки прямо исключены из минимального pricing-модуля. Это сознательная граница старого предложения, а не дефект реализованного кода; принимать ее как границу нового проекта оснований пока нет. [Исходная граница pricing](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:335).

Воспроизведенные **16/16 проверок прошли** на Python 3.12.3 / SQLite 3.45.1. Это подтверждает только содержащиеся в скрипте примеры. Нет реализации предложенных таблиц, Laravel-пакета, испытаний заявленных СУБД или доказательства поведения реальной конкурентной обработки заказов. Сам автор исходника честно указывает эти ограничения. [Исходная оговорка](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:2), [новый вывод](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/audit/semantic-checks-reproduced.stderr.txt:1).

## Происхождение и воспроизводимость

Изучены только эти входные артефакты старого аудита:

| Файл | Объем | Роль |
|---|---:|---|
| [architecture-appendix.md](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:1) | 448 строк | Предлагаемая логическая архитектура и таблицы |
| [verification-matrix.md](/home/vostrikov/projects/packages/commerz/audits/chatgpt/verification-matrix.md:1) | 122 строки | Семантические примеры плюс будущие интеграционные проверки |
| [semantic_checks.py](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:1) | 284 строки | Самостоятельная иллюстративная программа |
| [semantic_checks_results.txt](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks_results.txt:1) | 21 строка | Прежний вывод без собственной метаинформации среды |

Перед запуском код прочитан целиком: стандартная библиотека, арифметика, SQLite в памяти и новые временные файлы. Сеть, приложение и его БД не используются. Запуск проведен в новом временном каталоге с отдельным `TMPDIR` и `python -B`; исходные файлы не редактировались. SHA-256 всех четырех входов до и после совпали. Команда, версии, время UTC и хеши находятся в [reproduction-manifest.json](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/audit/reproduction-manifest.json:1). Прежний результат не подменялся новым.

Результат запуска занимает 0.771 секунды по `unittest`; это не замер производительности архитектуры. Результаты будущих сценариев ниже не заявляются как полученные.

## Находки, влияющие на следующее проектирование

Обозначения: **пробел** — вопрос не решен в предложении; **напряжение** — две заявленные цели требуют дополнительного механизма; **неоднозначность** — допустимы разные трактовки; **ограничение доказательства** — тест не подтверждает более широкий вывод. Это приоритеты исследования, а не нарушения еще не принятого стандарта.

### A01. Предложение заканчивается раньше полного checkout

**Пробел, высокий приоритет.** В перечне пакетов нет cart/order/promotion/payment/return; минимальный pricing их исключает. `inventory_reservations.owner_reference` специально не зависит от заказа. Это позволяет независимый складской пакет, но не определяет координатор, который свяжет цену, право на скидку, запас и состояние оплаты. [Границы пакетов](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:36), [резерв](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:251).

Сценарий: последний экземпляр товара и последнее использование купона достаются разным параллельным checkout; платеж одного покупателя успешен после освобождения его резерва. Ни balance, ни outbox отдельно не задают правильную компенсацию. Нужны явные состояния order placement, stock hold, promotion hold, payment authorization/capture; момент создания обязательства и правила позднего callback.

Следствие: до DDL следует выбрать, какие из этих доменов входят в Commerz, а какие предоставляются адаптерами. «Платежи снаружи» не снимает обязанность иметь устойчивый контракт с ними.

### A02. Обещание новых типов свойств не доведено до механизма хранения

**Напряжение, высокий приоритет.** Схема фиксирует перечень `kind` и typed payload; extension API обещает новый тип со своими normalize/validate/operators/index/migration. Нет ответа, где хранить payload неизвестного ядру типа, кто добавляет FK, как подключить миграции и как читать данные после отключения плагина. [Payload](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:161), [extension points](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:412).

Сценарий: плагин `geopoint`, денежный диапазон, ссылка на модель приложения или набор сертификатов требует больше одной существующей колонки и собственного индекса. Название интерфейса само по себе этого не решает.

Варианты для прототипа: (1) закрытые физические типы и новые семантические типы поверх них; (2) typed extension-таблицы с FK и plugin-owned migrations; (3) версионированный JSON payload для некритичных типов плюс отдельные индексные проекции. Для каждого проверить экспорт/импорт, установку, upgrade, отсутствующий плагин, индексирование и безопасное отключение.

### A03. Важные межстрочные инварианты заявлены, но не представлены ограничениями схемы

**Пробел, высокий приоритет.** Отдельные FK не обеспечивают, что `schema_version` относится к тому же `attribute`, group и atom — к тому же value, option — к нужному dictionary, axis — к продукту варианта. Сам текст признает обязательность этих проверок, но таблицы перечисляют только часть необходимых ключей. [Инварианты](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:163), [оси](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:98).

Это не доказанная ошибка миграций: миграций еще нет. Это место, где нельзя механически преобразовать Markdown в `foreignId()->constrained()` и считать контракт выполненным. Нужен список каждого инварианта с владельцем проверки: составной FK/UNIQUE, constraint, транзакционная команда, аудит целостности. Ограничение `CHECK` не является общим решением для зависимостей между строками и таблицами; это явно оговорено, например, в [документации PostgreSQL 16 о CHECK](https://www.postgresql.org/docs/16/ddl-constraints.html#DDL-CONSTRAINTS-CHECK-CONSTRAINTS).

Отдельно `single` требует ограничения количества atoms, `set` — уникальности нормализованного значения, `clear` — отсутствия дочерних payload; перечисленная уникальность `(group_id, schema_node_id, ordinal)` сама этого не гарантирует. Под «обязательным ограничением» нужно различать бизнес-инвариант и конкретный SQL constraint.

### A04. Публикация версии схемы нуждается в протоколе с конкурентными авторами

**Пробел, высокий приоритет.** Неизменяемые версии и expand/backfill/switch — хорошее направление, но одновременная запись карточки по старой схеме во время переключения может оставить свежие старые значения уже после backfill. В appendix нет транзакционного протокола активации, версии клиента, границы блокировки и обработки частично мигрировавших значений. [Версии](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:125), [предложенный ADR 17](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:438).

Нужны отдельные сценарии: добавить обязательное поле; decimal→measurement; single→set; option archive; переименовать код; поменять набор variant axes. Часть изменений меняет только представление, часть — идентичность и поисковые операторы. Объединять их одним `schema_version++` недостаточно.

### A05. Тест последнего экземпляра не проверяет модель конкурентной работы пакета

**Ограничение доказательства, высокий приоритет.** Тест запускает 16 потоков, но вся запись одного SQLite-файла сериализуется `BEGIN IMMEDIATE`. Проверяется один `balance id=1`, положительное целое quantity, один command/event и отсутствие повторного резерва. [Операция](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:99), [тест](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:204).

Он действительно полезен: атомарный условный UPDATE и запись результата команды дают требуемый результат в этой фикстуре. Он не проверяет row locks выбранной production-СУБД, многокомпонентные заказы, частичный rollback, retry после deadlock, конкурирующие одинаковые ключи, race expire/confirm, смену warehouse policy и crash после commit. SQLite официально допускает только одну одновременную пишущую транзакцию; `BEGIN IMMEDIATE` начинает ее сразу. [SQLite Transaction](https://www.sqlite.org/lang_transaction.html).

Нужно сохранить маленький пример и добавить, после выбора СУБД, отдельный набор настоящих процессов/соединений с управляемыми барьерами. Сто успешных повторов «по времени» хуже одного детерминированно воспроизведенного interleaving.

### A06. Возврат товара, возврат денег и восстановление лимита акции еще не связаны

**Пробел и неоднозначность, высокий приоритет.** Единственный `ReturnStock` не моделирует RMA, проверку состояния, частичный возврат, refund и распределение скидки. Фраза «возможно сначала unavailable += q» требует уточнения: если `unavailable` — подмножество `on_hand`, физически принятый в карантин товар должен увеличить оба счетчика; увеличение только unavailable уменьшает доступность уже имевшегося запаса. Это неоднозначность сокращенного описания, а не установленный bug. [Команды](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:275).

Сценарий: заказ из трех единиц со скидкой 100 денежных единиц возвращается частями; сумма округленных компенсаций не должна превысить исторически уплаченное. Возврат денег может быть одобрен без физической приемки; приемка может произойти до refund. Купон может восстанавливать право использования по разным политикам; это отдельное бизнес-решение.

### A07. Transit и снимок рецепта существуют в тексте, но их хранение не завершено

**Пробел, средний/высокий приоритет для inventory.** Команды перевода требуют transit, но список канонических таблиц не называет ни transit account, ни shipment leg, ни отдельную транзитную позицию. `reservation_allocations` не содержит ссылку на recipe snapshot или зафиксированные conversions, хотя сохранение рецепта заявлено обязательным. [Таблицы](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:244), [переводы](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:283), [комплекты](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:317).

Возможные завершения: transit как тип inventory account; документы перемещения с остатком dispatched−received−lost; отдельный модуль fulfillment/transfer. Рецепт можно хранить в immutable reservation request snapshot либо нормализованных requirement lines. Сначала выбрать семантику, затем поля.

### A08. Версия одного агрегата не решает обновление составной витринной проекции

**Пробел, средний/высокий приоритет.** Тест `snapshot_projection_rejects_older_versions` проверяет один словарь с одним счетчиком. Реальная `OfferView` зависит от variant, stock, price, publication, policy, locale и схемы свойств; их версии несравнимы между собой. [OfferView](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:22), [проекции](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:203), [тест](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:263).

Пример: inventory version 100 не может подавлять price version 7. Возможные стратегии — отдельные проекции по независимым размерностям, перечитывание актуальных источников при инвалидации с собственным CAS поколения, version vector или выделенный поток последовательности. Решение зависит от нагрузки и требуемой свежести; старое предложение правильно предупреждает о размерностях, но не выбирает этот механизм.

### A09. Распределение пакетов и связь с экосистемой пока гипотеза

**Пробел относительно текущего запроса, средний приоритет.** Prefix отложен; рекомендованный BIGINT+public_id, зависимости на `stores` и набор Composer-пакетов не подтверждены анализом Chatom/Vaulter/Azguard. Значит, эти решения нельзя перенести как уже согласованные правила экосистемы. [Prefix/keys](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:59), [проверить Composer прототипом](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:55).

Также есть явная цель независимого inventory и возможность устанавливать catalog только вместе со stores. Это допустимые trade-off, но нужны пользовательские сценарии установки. Альтернатива для исследования: один Composer-пакет с внутренними модулями и отдельные адаптеры до появления доказанных независимых потребителей; это сохраняет ясные доменные границы без раннего умножения релизов.

### A10. «Гибкость» требует правил взаимодействия расширений

**Пробел, высокий приоритет для переиспользования.** Список strategy/registry интерфейсов не задает порядок нескольких обработчиков, типы ошибок, транзакционный контекст, допустимые побочные эффекты, зависимость плагинов, collision namespace, версию контракта и удаление плагина с историческими данными. [Предложенные контракты](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:397).

Разные extension points требуют разной модели: один выбранный allocation strategy; детерминированная последовательность promotion calculators; many-subscriber post-commit events; реестр именованных attribute types; connector protocol. Универсальный «плагин может переопределить все» лишает ядро возможности гарантировать инварианты. Нужны маленькие независимые экспериментальные расширения, а не только перечень интерфейсов.

### A11. Idempotency требует решения о сроке, области и внешнем результате

**Пробел, средний приоритет.** Таблица receipts задает scope/type/key и payload hash, что полезно. Не определены канонизация payload, срок хранения, повтор отвергнутой команды после пополнения склада, удаление результата, одновременное «in progress», потерянный ответ и повтор после upgrade версии API. [Receipts](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:341).

В фикстуре rejected result сохраняется и при том же ключе остается rejected после изменения склада; это согласуется с принципом возвращать прежний результат, но контракт должен явно требовать новый ключ для нового намерения. Тест одинакового ключа сейчас последовательный и сравнивает только quantity; это не полная проверка payload fingerprint. [Повтор](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:107), [тест повтора](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:222).

## Что стоит сохранить как рабочие гипотезы

| Сильная сторона | Почему полезна | Что еще опровергать |
|---|---|---|
| Product / Variant / InventoryItem | Упаковка и комплект могут делить один физический ресурс; digital sellable не требует фиктивного склада | Производство под заказ, аренда/временная емкость, уникальные экземпляры, subscription |
| Один вариант для простого товара | Уменьшает число веток API цены/заказа | Товар без опубликованной продаваемой конфигурации; дорогостоящая персонализация без хранения всех combinations |
| Family отдельно от category | Смена мерчандайзинга не ломает обязательные свойства | Несколько семейств/наборов требований, атрибуты по market/regulation |
| Типизированные значения + sameVariant/sameObject | Предотвращает ложные совпадения составных фильтров | Цена и availability с несколькими контекстами; наследование Product→Variant |
| Отдельные clear/missing/empty | Сохраняет намерение отключить наследование | Default values, empty string, set merge, patch объектов, различие unknown/not-applicable |
| Общий физический остаток с центральным арбитражем | Не копирует одну единицу между каналами | Consignment, owner, lot, branch outages, offline POS |
| Stock ledger + reserve overlay | Не путает физическое движение и обязательство | Исправления задним числом, stocktake cutoff, reconstruction consistency |
| Outbox/inbox и source ownership | Устраняет часть окон потери событий и неоднозначность внешней истины | Poison message, пропуск события, импорт старой версии, lag и tombstones |
| Capability contract поиска | Не обещает равенство разных движков без проверки | Сложные nested facets и точные distinct counts на целевом объеме |
| Прямо оговоренные границы доказательства | Не выдает предложения за выполненную реализацию | Сохранить эти оговорки в новом синтезе |

Источники этих гипотез: [понятия](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:7), [свойства](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:163), [поиск](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:216), [остаток](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:257), [интеграции](/home/vostrikov/projects/packages/commerz/audits/chatgpt/architecture-appendix.md:356).

## Что фактически покрывают 16 тестов

| Тест / строки скрипта | Реальное доказательство | Не доказано |
|---|---|---|
| 01 / [134](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:134) | Сортировка трех строк отличается от Decimal | SQL collation, precision overflow, currency rounding |
| 02 / [139](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:139) | Независимые EXISTS дают ложное совпадение цвета и размера | Typed EAV, унаследованные свойства, search adapter |
| 03 / [150](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:150) | Корреляция availability и выбор цены синего SKU | Комбинированный price predicate, price lists, customer context, Quote |
| 04 / [163](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:163) | DDR5 и capacity должны принадлежать одной строке | Реальная schema/groups/atoms и ее FK/индексы |
| 05 / [174](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:174) | Два варианта одного продукта — один product count | Approximate distributed count, multi-facet aggregation |
| 06 / [179](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:179) | Один self-excluding size facet с color+stock | Общий компилятор AND/OR/NOT, permissions и scope |
| 07 / [185](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:185) | missing sentinel отличается от [] и None | Шесть уровней fallback, provenance, false/zero, кэш |
| 08 / [193](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:193) | Два скопированных Balance создают ложный запас | Реальные channels/pools и общий арбитраж |
| 09 / [204](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:204) | Один положительный integer резерв из 16 SQLite writers | Production row locking и жизненный цикл заказа |
| 10 / [222](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:222) | Последовательный повтор key/quantity не удваивает reserve | Конкурентный duplicate, timeout, crash, payload version |
| 11 / [233](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:233) | Один mutable Balance правильно уменьшен и освобожден | Allocation identity, повтор ship command, per-order предел |
| 12 / [243](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:243) | Прямое изменение on_hand сохраняет reserved | Stock count transaction, discrepancy record, cutoff |
| 13 / [251](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:251) | Две точные конечные Decimal операции | Библиотека units, nonterminating conversion, dimension checking |
| 14 / [255](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:255) | Pooled и colocated capacity могут различаться | Общий allocation solver, quantity ratios, shared components |
| 15 / [263](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:263) | Новый snapshot словаря не заменен старым | Outbox, multi-aggregate versioning, rebuild/delete race |
| 16 / [271](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:271) | SQLite допускает два NULL scope, явный global UNIQUE помогает | Конкретные ограничения других СУБД и canonical scope integrity |

В catalog fixture вообще нет предложенных `attributes`, schema versions, groups/atoms, channel, warehouse, pricing context и foreign keys: есть `variants` с пятью скалярными колонками и `parts`. [DDL фикстуры](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:51). Поэтому выражение «схема проверена тестами» было бы неверным.

`Balance` не принимает reservation/allocation id и не хранит command id: название теста «уменьшает ... once» означает один вызов с проверкой арифметики, не доказанную идемпотентность отгрузки. Отдельные команды будущей модели обязаны обеспечить attribution и дедупликацию. [Balance](/home/vostrikov/projects/packages/commerz/audits/chatgpt/semantic_checks.py:23).

Исходная verification matrix честно отделяет будущие интеграционные и нагрузочные тесты. В этой проверке они **не выполнялись**. В нее следует добавить commerce checkout/coupon/refund и plugin lifecycle, если эти домены входят в новый пакет. [Будущие интеграционные сценарии](/home/vostrikov/projects/packages/commerz/audits/chatgpt/verification-matrix.md:26), [невыполненные нагрузочные испытания](/home/vostrikov/projects/packages/commerz/audits/chatgpt/verification-matrix.md:110).

## Альтернативы, которые нельзя закрывать старым аудитом

1. **Typed EAV как canonical storage против versioned JSON + typed projections.** Старый ADR сравнивает typed EAV в основном со «всем строкой» и «все домены в EAV». Это не вся область вариантов. JSON с формальной схемой, validation и nested indexes не равен неструктурированному JSON. Прототипы должны сравнить atomic edit composite, запросы, число строк, write amplification, migration и plugin types на одинаковой выборке.
2. **Ранний physical allocation против pool-level promise с общим арбитражем.** Резерв на физических источниках удобнее гарантировать в v1, но может ухудшать маршрутизацию и вызывать переносы при изменении доставки. Pool-level promise не обязательно ошибочен: он требует отдельного централизованного ограничения для пересечений и протокола позднего allocation. Проверять нужно стоимость и корректность, а не запрещать термин pool.
3. **Stock ledger против account/transfer-ledger модели.** Простой `(item, warehouse)` хорош для базового учета, однако общие измерения owner/condition/transit иногда нужны с первого клиента. Нужно проверить миграционный путь к InventoryAccount; он может быть проще ранней абстракцией, а может перегрузить типовой магазин. Данных для окончательного выбора нет.
4. **Always variant против sellable interface.** Единая Variant-модель сокращает код; альтернативный sellable contract может объединять SKU, услуги, цифровые права и подписки. Цена альтернативы — сложнее ссылки и snapshots. Важно сохранить одинаковый checkout contract без насильственного придумывания склада или комбинации осей.
5. **Один пакет против набора независимых пакетов.** Внутренняя модульность не требует сразу 10 Composer releases. Изоляцию сначала можно доказать отсутствием обратных зависимостей и независимыми сценариями, затем выделять распространение.
6. **Наличие одной БД против одного authority API.** Модульный монолит — удобная стартовая гипотеза. Если существующие проекты уже разделяют физический склад, интерфейс Inventory authority важен раньше UI. Это не означает обязательный переход всех модулей на микросервисы.

Эти альтернативы не являются рекомендацией немедленно заменить исходное предложение. Они сформулированы для честного сравнительного исследования. Доказательство следует искать в прототипах и потребностях первых нескольких проектов.

## Продолжение для Claude Code и следующего прохода

Практический набор сценариев и гипотез находится в [audit/scenario-lab.md](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/audit/scenario-lab.md:1). В нем помечено, где требуется продуктовый выбор и что именно должна наблюдать проверка.

Проверяющему полезно отдельно оспорить A02, A03, A06, A07 и A08 по точным строкам исходного текста: возможно, часть недостающего механизма подразумевалась вне этих четырех файлов. В таком случае нужно добавить источник и снять соответствующую находку, а не объявлять недоказанный механизм реализованным.

Ни одна предложенная здесь таблица, граница пакета, тип PK, база данных, исключение из v1 или правило купона не приняты автоматически. В рамках этого разбора изменены только новые файлы `docs/discovery/2026-10-08/audit/*` и этот отчет.
