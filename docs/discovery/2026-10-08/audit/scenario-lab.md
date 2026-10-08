# Лаборатория сценариев для Commerz

Дата: 2026-10-08. Статус: **неисполненные** исследовательские сценарии и конкурирующие гипотезы. Это дополнение к критическому разбору исходного ChatGPT-аудита, не принятые критерии первого релиза. Воспроизведен только его отдельный скрипт из 16 тестов; происхождение результата — в [reproduction-manifest.json](reproduction-manifest.json).

Цель — проверить смысл сущностей и точек расширения до фиксации DDL. Не следует пытаться заранее реализовать все приведенные случаи. Сначала выбрать репрезентативные потребительские проекты, затем исключить явно чуждые сценарии с записанной причиной.

## Минимальный набор предметных миров

| Мир | Почему способен опровергнуть слишком простую модель |
|---|---|
| Одежда | Цвет/размер, переводы и картинки вариантов, несколько витрин, размеры разных систем |
| Электроника B2B | Измерения, повторяемые связанные характеристики, цены по количеству, версии спецификаций |
| Продукты/расходники | Весовой товар, упаковка, точность количества, срок годности, карантин возврата |
| Наборы | Общие компоненты, комплект виртуальный или предсобранный, split/co-location, возврат части |
| Услуги и цифровые товары | Нет физического остатка; доступ/срок/активация нельзя автоматически считать количеством на складе |
| Несколько приложений | Одно физическое наличие продается разными системами; требуется общий authority или непересекающиеся квоты |

Последние два мира могут оказаться внешними адаптерами, но это должен быть явный ответ, а не случайное ограничение `ProductVariant`.

## Товары, варианты и типизированные свойства

| ID | Сценарий | Ожидание или решение, которое нужно проверить | Связь с исходным аудитом |
|---|---|---|---|
| CAT-01 | Карточка-черновик создана без SKU и цены | Выбрать: есть скрытый default variant или sellable появляется позже; idempotent create не дублирует вариант | Always variant, appendix:24 |
| CAT-02 | Футболка red/S отсутствует, blue/M есть; фильтр red+M | Товар не совпадает, даже если каждое значение встречается отдельно | Уже иллюстрирует semantic test 02 |
| CAT-03 | У red/S цена 100 без наличия, у blue/M цена 200; сортировка по red+available | Не использовать дешевый неподходящий вариант | Частично иллюстрирует test 03 |
| CAT-04 | У товара есть DDR4/16 и DDR5/8 | Фильтр DDR5>=16 не совпадает; reorder строк сохраняет identity | Test 04 проверяет только плоскую фикстуру |
| CAT-05 | Значения false, zero, empty string, [], clear, missing | У каждого состояния явный контракт; fallback не использует PHP truthiness | Test 07 покрывает только три значения |
| CAT-06 | Локальный канал имеет fallback язык, глобальный слой — requested язык | Утвердить приоритет semantic locale против channel override; возвратить provenance | appendix:182 — только предложенный порядок |
| CAT-07 | Product-level material наследуется variant; variant задает clear | Card API, search и export должны разрешить одинаковое значение | Сам механизм Product→Variant fallback не завершен |
| CAT-08 | Два properties используют разные dictionary с одинаковыми option code | Option чужого справочника отклоняется, включая прямой импорт | Межтабличный invariant |
| CAT-09 | Axis другого продукта привязали к варианту | Отклоняется независимо от совпадения значения; проверить реальное ограничение БД | appendix:100 |
| CAT-10 | Размер 42 и размер 42 EU/US | Идентичность включает систему размеров; локализованная надпись не становится идентификатором | dictionary/measurement boundary |
| CAT-11 | 1.25 m → 125 cm; 1 inch; повторный импорт другой единицей | Канонический смысл воспроизводим; unit family и rounding заданы; нет бесконечного изменения от повторов | Test 13 не проверяет resolver единиц |
| CAT-12 | Локализованное имя option меняется | Identity variant и комбинация не меняются; поисковая проекция обновляется | appendix:115 |
| CAT-13 | Добавлена новая axis к товару с существующими заказами | Выбрать миграцию текущих комбинаций, историю old SKU и поведение ссылок | appendix:115 оставляет миграционный сценарий |
| CAT-14 | Два процесса создают одну комбинацию одновременно | Один успех или один стабильно идентичный результат по API; UNIQUE — последняя защита | Нет такого исполняемого теста |
| CAT-15 | Старый writer пишет decimal после переключения схемы на measurement | Reject/rebase/dual-write по явному протоколу; backfill не считается завершенным преждевременно | A04 |
| CAT-16 | Новый тип `geopoint` от плагина | Возможны serialize, validate, индекс, backup/restore, upgrade, чтение при отключенном плагине | A02/A10 |
| CAT-17 | Ручная персонализация: надпись на футболке | Выбрать order configuration snapshot без создания миллионов variants либо отдельный тип sellable | Не покрыто |
| CAT-18 | Товар по метрам и отрезками; продаваемая упаковка 2.5 m | Развести sellable unit, base unit, required amount, allowed quantity step | Quantity/InventoryItem boundary |

## Склады, резервы, переводы

| ID | Сценарий | Что наблюдать | Необходимая среда |
|---|---|---|---|
| INV-01 | Два канала одновременно резервируют последнюю единицу | Ровно одно принятое обязательство; другие получают устойчивую доменную ошибку | Выбранная production-СУБД, разные соединения/процессы |
| INV-02 | Два stock pool пересекаются одним складом | Общее обещание не превышает реальный общий запас | Тот же authority, реальные pool mappings |
| INV-03 | Заказ A→B и заказ B→A; обоим нужны оба ресурса | Deterministic lock order; если deadlock случился, повтор всей команды с прежним ключом | Управляемые барьеры, не случайный sleep |
| INV-04 | Первый компонент зарезервирован, на втором ошибка | Rollback всех allocations, events, receipts/outbox по выбранному контракту | Failpoint после первой записи |
| INV-05 | Один и тот же command key отправлен конкурентно | Один эффект, одинаковый конечный результат; другой payload — конфликт | Разные процессы, задержка первого перед commit |
| INV-06 | Сервер committed reserve, ответ потерян | Повтор возвращает существующее обязательство; без второго эффекта | Failpoint после commit до ответа |
| INV-07 | В один момент expire и confirm | Единственное допустимое конечное состояние; stock соответствует ему | Fake clock + блокировка reservation aggregate |
| INV-08 | Частичная отгрузка и отмена одного allocation конкурентно | shipped+released<=allocated; повтор обоих сообщений безопасен | Реальные allocation id и receipts |
| INV-09 | Недостача после stocktake: on_hand=3, reserved=4 | Reserve не исчезает; available=0; discrepancy открыт; fulfillment policy явна | Count cutoff/ledger, не присваивание переменной |
| INV-10 | Приемка и инвентаризация пересеклись | Одинаковое физическое движение не потеряно и не учтено дважды | Фиксированный cutoff и заранее заданный interleaving |
| INV-11 | Исходный склад отправил 10, получатель принял 7, потеря 2, 1 в пути | Физический баланс сохранен между source/transit/destination/loss; документ не закрыт преждевременно | Прототип transit representation |
| INV-12 | Возврат 2 единиц принят в карантин | on_hand и unavailable меняются согласованно; sellable stock до inspection не растет | Выбранная семантика unavailable |
| INV-13 | В комплектах X и Y один и тот же компонент | Требования агрегируются до проверки; нельзя считать независимые capacities как суммируемые гарантии | Реальный reservation planner |
| INV-14 | Рецепт комплекта изменен после резерва | Отгрузка и отмена используют сохраненный состав, а не текущую карточку | Immutable recipe/request snapshot |
| INV-15 | Компоненты есть только на разных складах | Результат зависит от split/co-location policy, цены доставки и целостности заказа | Allocation strategy + policy context |
| INV-16 | Warehouse отключен от канала во время reserve | Новые обязательства не проходят по устаревшей policy; старые сохраняются | Policy version/read lock protocol |
| INV-17 | SKU упаковки и SKU единицы продают один InventoryItem | Продажа упаковки списывает общий ресурс в точной базовой единице | Decimal step + conversion snapshot |
| INV-18 | Один физический склад продают две независимые установки | Отсутствие oversell можно гарантировать только выбранным межсистемным authority/quota contract | Два приложения; локальная БД не заменяет опыт |
| INV-19 | Клиент требует lot/serial/owner/quality | Проверить, можно ли без потери истории перейти с item×warehouse на InventoryAccount | Проект миграции и reconciliation |
| INV-20 | Повтор исторического stock document | Повтор не добавляет ledger entry; исправление идет отдельной компенсацией | Stable posting identity и uniqueness |

Текущее предложение для строгого резервирования из одной БД — рабочая гипотеза. Отрицательный on_hand/backorder, consignment и stocktake shortage требуют разных политик: один универсальный CHECK `on_hand >= reserved` ломает учет уже существующих обязательств.

## Купоны и promotions: отсутствующий в исходнике домен

Пока полезно различать четыре сущности: promotion rule, предъявляемый coupon code, временное удержание права использования, исторический redemption. «Купон» не должен одновременно означать все четыре. Budget, per-customer limit, validity и stackability принадлежат политике акции, а не одному изменяемому `used_count`.

Это гипотеза словаря, а не окончательные названия моделей/таблиц.

| ID | Сценарий | Нужное решение или invariant |
|---|---|---|
| PRO-01 | Осталось одно использование, два checkout | Atomic limit enforcement; один accepted hold/redemption; cache eligibility не подтверждает право |
| PRO-02 | Coupon валиден в cart, но истекает до оплаты | Определить момент фиксации права: quote/place/authorize/capture; saved policy version |
| PRO-03 | Гость переходит в аккаунт; купон один раз на клиента | Выбрать customer identity и merge policy; email/IP/device не считать автоматически эквивалентными identity |
| PRO-04 | Один код регистронезависим, другой code namespace от партнера | Canonicalization и uniqueness зафиксированы в scope; не ломать коды произвольным upper() |
| PRO-05 | Одинаковый coupon в двух каналах с разными лимитами | Общий и per-channel budgets могут быть вложенными; их проверка атомарна |
| PRO-06 | Оплата отклонена/отменена/не пришла | Hold освобождается по выбранному моменту; повтор release безопасен; окончательный redemption не исчезает без причины |
| PRO-07 | Late success callback после истечения hold | Либо восстановить все права/остаток атомарно, либо компенсация оплаты, либо explicit manual conflict; никаких тихих обходов лимита |
| PRO-08 | Две совместимые процентные скидки и одна fixed discount | Детерминированный порядок; stacking groups; база расчета; запрет отрицательного payable |
| PRO-09 | Buy X get Y; Y отсутствует или возвращен | Выбрать, что является qualifying line/reward line; сохранять историческое распределение |
| PRO-10 | У акции общий денежный бюджет, валюты разные | Либо независимые бюджеты валют, либо зафиксированный FX rate/snapshot; не суммировать несовместимые amounts |
| PRO-11 | Частичный возврат заказа с once-per-customer coupon | Политика восстановления usage отдельно от refund суммы; исторический redemption остается аудируемым |
| PRO-12 | Два plugins считают цену и купон по-разному | Контракт input/output adjustment, последовательность, collision handling; по результату можно объяснить расчет |
| PRO-13 | Refund и новый checkout одновременно претендуют на восстановленный лимит | Atomic state transition и новый hold не удваивают восстановленную емкость |
| PRO-14 | Promotion rule изменили после order placement | Итог заказа и возврата основан на зафиксированных adjustments; не пересчитывается автоматически новой rule |

Для первого прототипа достаточно пары простых акций: fixed amount и percentage, один global и один customer limit. Сложные rule builders и BOGO не нужны, чтобы проверить правильность удержания лимита и распределения скидки.

## Заказ, деньги, возвраты

| ID | Сценарий | Наблюдаемый контракт |
|---|---|---|
| ORD-01 | Цена изменилась между quote и place | Quote имеет expiry/version; выбрана политика reprice/accept/reject; покупатель не получает молча иную сумму |
| ORD-02 | Частичные shipment и capture | Order line ordered/allocated/shipped/cancelled/returned и payment authorized/captured/refunded не сведены в один status |
| ORD-03 | Capture successful, локальная фиксация результата падает | Payment adapter принимает idempotency key; reconciliation разрешает неизвестный результат без второго списания |
| ORD-04 | Callback оплаты продублирован и пришел не по порядку | Inbox dedup + payment transition policy; provider event version/time не заменяет сверку состояния |
| ORD-05 | Ровно 100 скидки распределены на три одинаковые единицы | Сохраненное распределение суммируется ровно в 100; deterministic residual allocation |
| ORD-06 | Возвращается по одной единице из ORD-05 | Сумма refunds не превышает оплаченного; округление не генерирует деньги; replay не увеличивает refund |
| ORD-07 | Order-level discount и shipping частично компенсируются | Явные правила, какой adjustment относится к каким line/shipping/tax components |
| ORD-08 | Возврат одобрен, но товар не принят; товар принят, refund еще pending | Независимые state machines RMA/receipt/refund; состояние одного домена не подделывает событие другого |
| ORD-09 | Возврат вещи damaged | Физическое поступление может увеличивать quarantine/unavailable, без восстановления sellable quantity |
| ORD-10 | Возвращена часть виртуального набора | Выбрать refund allocation и inventory effect по фактическим компонентам; текущий recipe не применяется задним числом |
| ORD-11 | Товар/вариант архивирован после продажи | Order сохраняет title/SKU/options/price/unit/tax/discount snapshots; история не зависит от нынешней карточки |
| ORD-12 | Два refund request на последнюю некомпенсированную сумму | Atomic refundable cap, stable external command id и reconciliation |

Эти сценарии задают инженерные вопросы, а не юридические правила возврата; конкретные права покупателя и налоги зависят от проекта и юрисдикции.

## Plugins, события, индекс

| ID | Сценарий | Нужная наблюдаемость/гарантия |
|---|---|---|
| EXT-01 | Два плагина регистрируют один type code | Предсказуемая ошибка либо explicit override с приоритетом; namespaces документированы |
| EXT-02 | Отключается плагин типа, на который ссылаются товары/заказы | Запрет отключения, read-only unknown type, либо согласованная migration; данные не удаляются автоматически |
| EXT-03 | Плагин пишет событие в сеть внутри stock transaction | Контракт запрещает или контролирует такое поведение; external effects проходят согласованный durable mechanism |
| EXT-04 | Второй price calculator запускается после третьего из-за provider order | Порядок декларативен и тестируем, не зависит от случайного boot order |
| EXT-05 | Plugin upgrade меняет normalization | Версия normalization/index; миграция и compatibility; старые snapshots читаются |
| EXT-06 | Один queue worker обслуживает два sales contexts | Явный context; нет утечки tenant/channel/currency между jobs |
| EVT-01 | Outbox publisher упал после отправки до отметки sent | Повторная доставка не удваивает эффект; sender не заявляет exactly-once |
| EVT-02 | Inventory v100 и price v7 обновляют общий OfferView | Не сравнивать версии разных aggregates как одно число |
| EVT-03 | Delete и старый snapshot пришли в обратном порядке | Tombstone/version сохраняет удаление; TTL tombstone согласован с replay window |
| EVT-04 | Full rebuild идет одновременно с updates и deletes | Snapshot boundary/checkpoint + catch-up; переключение поколения не теряет изменения |
| EVT-05 | Consumer получил delta 12 без 11 | Gap обнаружен, retry/rebuild явен; ignore older не превращается в потерю движения |
| EVT-06 | Плагин публикует невалидное событие | Schema/version validation, poison queue, operator repair; head-of-line blocking не скрыт |

## Независимые эксперименты перед выбором архитектуры

| Вопрос | Гипотеза A | Гипотеза B | Как сравнить честно |
|---|---|---|---|
| Canonical attribute storage | Typed EAV/header/group/atom | Versioned JSON с typed проекциями | Одинаковые товары, sameObject запросы, atomic edit, backfill, индекс, размер данных и число write operations |
| Дополнительный тип от плагина | Композиция существующих storage types | Plugin-owned typed payload table | Один plugin вне repository ядра; установить, обновить, отключить и восстановить backup |
| Inventory scope | Item×warehouse + отдельные детализации | InventoryAccount с выбранными dimensions | Обычный магазин и один lot/owner сценарий; сложность API, key cardinality, reconciliation |
| Момент allocation | Сразу warehouse-level | Pool promise, позднее warehouse allocation | Пересекающиеся пулы, split order, смена доставки; safety, retry rate, shipping quality |
| Распространение | Один Composer-пакет с внутренними модулями | Несколько автономных packages | Установить catalog-only/inventory-only/full; число dependencies, миграции, релизная связанность |
| Sellable | Все через Variant | Sellable contract + типы fulfillment | Одежда, услуга, digital; число веток checkout, ссылки, price snapshots и API ergonomics |
| Продолжение отказавшей команды | Rejected receipt навсегда для key | Retryable failure допускает новое выполнение | Четко различить final rejection и transient failure; не менять эффект уже успешной команды |
| Multi-source projection | Отдельные dimension projections | Общая проекция с dependency versions | Price/stock bursts, reorder events, rebuild; lag, consistent results, write amplification |

Для каждого эксперимента сохранять: исходную гипотезу, dataset seed, версии среды, команду запуска, ожидаемый invariant, фактический результат, ограничения вывода и решение «принять / опровергнуть / недостаточно данных». Сравнение количества таблиц само по себе не доказывает простоту.

## Порядок следующего прохода

1. Согласовать domain vocabulary и 3–4 реальных профиля переиспользования. Отличить обязательный inventory/catalog от optional order/promotion/payment/WMS.
2. Провести два минимальных attribute storage прототипа и один plugin type вне ядра; до выбора хранения записать результаты.
3. Выбрать supported database behavior, затем доказать INV-01/03/04/05/07/08 на настоящих транзакциях; не подключать UI до устойчивого API операций.
4. Прототипировать checkout с одним coupon limit, одним stock item и fake payment adapter; воспроизвести late success, lost response, partial refund.
5. Проверить backfill/reindex при конкурентной записи и независимых price/stock versions.
6. Только после этого стабилизировать состав packages, DDL, naming prefix и первые принятые ADR. Это рекомендуемая последовательность исследования, а не авторизация реализации.
