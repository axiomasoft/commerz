# Независимый разбор исследовательского синтеза

Дата: 2026-10-08. Проверяющий: отдельный поток `audit_analysis`, не автор синтеза. Статус: review исследовательских артефактов; не приемка реализации и не утверждение ADR. Основные документы проверяющий не изменял: замечания передавались автору, изменения вносил автор.

Цель: найти смешение наблюдения и предложения, противоречия inventory/checkout, неподтвержденные обещания и проблемы воспроизводимости. Наличие исходников или будущего сценария не считается доказательством выполнения сценария.

## Замечания и разрешение

### SR-01 — Claim и redemption первоначально объединялись в словаре

**Статус: исправление перепроверено.** Начальный `CONTEXT.md:77` определял Redemption как состоявшееся либо удерживаемое право, тогда как `design-hypotheses.md:139` обсуждал Claim/Redemption отдельно. Это создавало две трактовки момента расходования coupon quota.

Контрпример: checkout удержал последний купон, оплата не состоялась, expiry освобождает право. Это не то же самое событие, что reversal уже состоявшегося redemption после возврата. Термин нужен до таблиц, чтобы две команды не уменьшали один счетчик повторно.

Рекомендация: отдельно определить временный Coupon claim и состоявшийся Redemption; политика восстановления usage остается открытым продуктовым решением.

### SR-02 — Восемь внешних source snapshots первоначально лежали только в `/tmp`

**Статус: исправление перепроверено.** В начальном `external/source-manifest.json` были immutable GitHub permalinks и SHA-256, но `local_temp_file` указывал на временный каталог. Все восемь файлов в момент проверки существовали и совпадали по хешам. Проблема — не достоверность вывода, а потеря локального материала после очистки временных файлов.

Рекомендация: сохранить выборочные исходники под `external/sources`, добавить durable relative path к manifest. Это соответствует запросу собрать исследование в папке проекта; наличие pinned upstream URLs уже позволяло восстановление через сеть.

### SR-03 — Eventually consistent поля commercetools могли быть прочитаны как eventual reserve enforcement

**Статус: исправление перепроверено.** Начальный `external-platforms.md:79` кратко сообщал, что изменения от резервов eventually consistent. Официальное описание делает более важное различие: read fields `InventoryEntry` догоняют изменения, а admission использует внутреннее real-time availability и сохраняет гарантию reservations. [Inventory checks and consistency](https://docs.commercetools.com/api/inventory-overview#inventory-checks-and-consistency).

Это существенная граница для Commerz: запаздывающее отражение не означает, что можно проверять последнюю единицу на asynchronous projection. В самом `design-hypotheses.md` эта граница уже соблюдена; замечание относится к точности внешнего референса.

### SR-04 — Правило typed payload нельзя свести к одной non-NULL колонке

**Статус: уточнено в logical schema; перепроверено.** `attribute-storage-options.md:84` говорит о «ровно одном значении» для нескольких typed columns. Это верно для семантического payload, но не универсально для физической колонки: measurement может содержать magnitude+unit, range — два конца. Исходный ChatGPT appendix уже отмечал это в строке 169.

Рекомендация: каждый storage kind определяет допустимую форму payload. Не делать generic CHECK «сумма всех non-NULL колонок = 1» обязательным контрактом расширяемых типов. Сам текст сравнения не содержит DDL и потому пока не демонстрирует ошибку реализации.

### SR-05 — Empty collection и missing требуют разных представлений

**Статус: исправление перепроверено.** Начальный `logical-schema-candidate.md:39–57` описывал rows с `ordinal`, `state=value/clear` и payload. При хранении набора по одной записи на элемент пустой набор дает ноль записей — как missing. Между тем `design-hypotheses.md:97` требует различать эти состояния для fallback.

Нужна логическая collection/header identity или другая явная empty representation. Конкретная физическая схема может оставаться открытой, но это состояние должно быть выразимо обоими сравниваемыми canonical storage.

### SR-06 — ReservationLine, Resource и Allocation должны совпадать по предметной identity

**Статус: исправление перепроверено.** В начальных строках 142/144 у line есть item и resource/pool, а у allocation снова resource. Независимые FK по ID не доказывают `line.item = resource.item`, общий scope, разрешенный pool membership и смысл active allocation после partial consume/release.

Контрпример: line требует InventoryItem A, allocation указывает на ресурс B; каждый ID существует. Даже correct arithmetic не превращает B в A. Список логических invariants должен содержать эти связи; механизм enforcement выбирается позже.

### SR-07 — Coupon-only claims не описывают общий лимит promotion

**Статус: пробел явно описан и добавлен кандидат ресурсов общей квоты; перепроверено.** Начальные строки 163–168 связывали лимит, claim и redemption только с coupon_id. Но automatic promotion допускается без coupon, а исследовательские сценарии включают общую квоту акции для разных кодов.

Контрпример: два разных кода одной акции каждый успешно блокируют свою coupon row, одновременно расходуя последний общий budget. Нужен отдельный адрес общей quota/claim или явная отметка, что такой режим пока вне спроектированной части. Наличие coupon claims не доказывает универсальную promotion admission модель.

### SR-08 — Уникальность Balance по surrogate Resource ID не исключает двойную физическую identity

**Статус: исправление перепроверено.** Начальные строки 101–118 задавали id ресурса и один balance на resource, но не явную уникальность `(scope, inventory_item, location, canonical dimension_key)`.

Контрпример: конкурентное первое поступление создает два resource ID для одной физической позиции; каждый получает свой balance. После этого last-unit command корректно блокирует каждый ID отдельно, но физический ресурс обещан дважды. Нужны logical uniqueness естественной identity и сценарий конкурентного создания первого balance/resource.

### SR-09 — GitHub snapshot семантически полный, но нормализован

**Статус: уточнение перепроверено.** Автор потока GitHub подтвердил наблюдение одного массива из 715 элементов непосредственно на raw CLI output до нормализации. Однако literal raw затем удален как дубликат; canonical JSON был заново сериализован. Поэтому manifest должен называть его normalized full metadata snapshot, а не подразумевать byte-for-byte stdout.

Это не опровержение полноты: количество, уникальные ID, hash canonical snapshot и страницы после конца проверены. Это уточнение уровня сохраняемой provenance.

## Независимо проверенные наблюдения

- GitHub canonical snapshot содержит 715 записей и 715 уникальных repository ID; его SHA-256 совпадает с `collection-manifest.json`.
- Индекс исходников содержит 78 уникальных `(repository, sha, path)`; каждый SHA соответствует `source-manifest.json`, каждый URL содержит этот SHA.
- Все восемь выбранных внешних source files при чтении совпали с SHA-256 manifest.
- Shopify API 2026-07 действительно содержит `InventoryItem.variants`, deprecated `variant` и `duplicateSkuCount`. Поэтому осторожный вывод о SKU и невозможности постулировать вечное 1:1 подтвержден. [Официальный InventoryItem](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/InventoryItem).
- commercetools документирует задержку смены состояния после expiry; это отдельно от срока действительности reservation. [Reservations](https://docs.commercetools.com/api/projects/reservations).

Не выполнялись: сторонние тесты, Laravel/DB integration, установка пакетов, checkout race, нагрузочные испытания и проверка всех веток каждого source repository.

## Оценка логики в проверенной области

В `design-hypotheses.md` физический ресурс, pool promise, адресное назначение и поисковая доступность не смешаны. Пересекающиеся пулы прямо обозначены как риск общего ресурса; внешняя оплата не объявлена частью локальной ACID-транзакции. Refund, физическая приемка и восстановление sellable inventory разделены.

`attribute-storage-options.md` не объявляет typed EAV единственно правильным. Есть явная схема, slots, relational options, string EAV, typed EAV, JSON и projection; оценки качественные и не выдаются за замеры. Тезис о «шести свойствах» не превращен в утверждение о шести колонках Unbox.

`ecosystem-conventions.md` различает declared compatibility и выполненную CI matrix, dirty working trees и immutable releases, after-commit и outbox. Это снижает риск механического переноса локальных паттернов с чужими гарантиями.

`perplexity-research.md` отдельно предупреждает о потере таблиц/inline citations при извлечении и о том, что `verified` внутри ответа Perplexity не является независимой проверкой. Raw research и проверенные выводы не смешаны.

## Остаточные вопросы следующего исследования

Даже после разрешения замечаний исследовательский пакет не выбирает database engine, tenancy, storage атрибутов, состав Composer-пакетов и границу v1. Это корректно для песочницы, но будущие миграции и обещания поддержки нельзя выпускать из данного материала автоматически.

Самые важные проверки следующего прохода: многоресурсная атомарность stock+coupon+order; частичный refund по сохраненным allocations; конкурентная активация schema version; независимые source revisions составной projection; plugin disable/upgrade с существующими данными. Эти вопросы должны иметь исполняемые прототипы, а не только новые интерфейсы.

## Финальная сверка

Прочитаны `design-hypotheses.md`, `attribute-storage-options.md`, `logical-schema-candidate.md`, `research-roadmap.md`, `README.md`, `CONTEXT.md`, `external-platforms.md`, `ecosystem-conventions.md`, `github-references.md`, `analysis-unbox.md`, `perplexity-research.md`, выбранные supporting evidence и manifests. Независимое чтение исходников третьих сторон было выборочным; весь source corpus повторно не сканировался.

| Замечание | Подтвержденное изменение |
|---|---|
| SR-01 | [Словарь](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/CONTEXT.md:77) отдельно определяет claim и redemption |
| SR-02 | [Manifest](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/external/source-manifest.json:1) содержит `local_snapshot`; восемь локальных файлов повторно совпали с SHA-256 |
| SR-03 | [commercetools](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/external-platforms.md:79) явно разделяет field propagation delay и актуальный reserve authority |
| SR-04 | [Payload](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/logical-schema-candidate.md:59) описан как kind-dependent union; generic one-non-NULL rule отвергнуто |
| SR-05 | [Collections](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/logical-schema-candidate.md:63) имеют явное пустое значение через header identity либо document state |
| SR-06 | [Reservation integrity](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/logical-schema-candidate.md:152) требует совпадения item/scope/pool; allocation.remaining определен отдельно; I-B отмечен незавершенным |
| SR-07 | [Promotion quota](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/logical-schema-candidate.md:178) имеет общий resource и claim group; coupon claim не расходует quota второй раз |
| SR-08 | [Physical identity](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/logical-schema-candidate.md:112) имеет явную уникальность и сценарий конкурентного первого создания |
| SR-09 | [GH provenance](/home/vostrikov/projects/packages/commerz/docs/discovery/2026-10-08/github/collection-manifest.json:1) и README прямо называют snapshot нормализованным |

Все девять замечаний обработаны на уровне исследовательских документов. Это не означает, что логические ограничения уже реализованы или что concurrency эксперименты прошли. Конкретные FK/CHECK/locking protocols, общая pool admission модель и quota amendment protocol остаются следующими проектными задачами и прямо обозначены как незавершенные.

В проверенной области после уточнений не найдено оставшегося противоречия, требующего остановить исследовательский синтез. Материал пригоден для следующего предметного обсуждения и независимого review Claude Code; он не является основанием объявлять архитектуру доказанной или генерировать production migrations без дальнейших решений.

Хеши прочитанных версий и результаты проверки ссылок/manifest находятся в [synthesis-review-manifest.json](synthesis-review-manifest.json). Manifest фиксирует момент проверки; последующие изменения документа потребуют сверять новую версию.
