# Perplexity research: каталог, inventory, promotions, границы пакета

**Дата:** 2026-10-08. **Статус:** четыре Deep Research выполнены; выводы выборочно проверены по первичным docs и исходникам. Финальная схема Commerz не выбрана.

Использован существующий соседний `perplexity-web-mcp` 1.4.0 через Shell и его daemon client: `searchDeep`. Настройки аккаунта и профиля не менялись; cookies/.env/credentials не копировались. В запросах были только общие архитектурные вопросы. Приватные исходники и локальный аудит в Perplexity не отправлялись.

## Что читать

| Материал | Назначение |
|---|---|
| [Проверенные выводы](perplexity/verified-findings.md) | 13 фактов с точными источниками, предложения для Commerz и сценарии проверки |
| [01 — Typed catalog](perplexity/01-typed-catalog.md) | EAV/JSON/structured references, product/variant/sellable/inventory identity, scopes, type evolution |
| [02 — Inventory and reservations](perplexity/02-inventory-reservations.md) | Остатки, allocations, ledger, concurrency, TTL, transfers, returns |
| [03 — Promotions/pricing/plugins](perplexity/03-promotions-pricing-plugins.md) | Money, rounding, stacking, coupon limits, snapshots, plugin contracts |
| [04 — Counterexamples and boundaries](perplexity/04-counterexamples-boundaries.md) | B2B, subscriptions, booking, marketplace, alternative architecture profiles |
| [Вопросы](perplexity/queries.json) | Полные исходные prompts |
| [Provenance](perplexity/provenance.json) | Время запросов, method, объём, hashes, ограничения извлечения |
| [Source leads](perplexity/source-leads.json) | Дедуплицированные ссылки из ответов; это очередь проверки, не список доказанных утверждений |
| [Source manifest](perplexity/sources/manifest.json) | 30 локальных snapshots исходников/лицензий Sylius, Lunar, Bagisto с commit-pinned URLs |
| [Runner](perplexity/run-research.mjs) | Воспроизведение через установленный локальный client, без извлечения credentials |

Получено **93 736 символов ответов**, четыре отдельных JSON + Markdown. Это объём извлечённого текста, а не оценка полноты или качества исследования. Наиболее полезный следующий шаг — проверять выводы и проектировать эксперименты, а не измерять прогресс количеством ссылок.

## Что уже изменяет направление проектирования

1. **Типизированные данные не требуют EAV по определению.** Lunar даёт типизированные PHP значения поверх JSON; Bagisto хранит EAV values в одной таблице с typed columns. Нужно отдельно выбрать logical type system, physical storage, validation, scope resolution и search projection. Источники и ограничения — F01–F05 в [проверенном разборе](perplexity/verified-findings.md).
2. **Persisted FQCN — реальная цена расширяемости.** Lunar cast хранит имя field-type класса, а отсутствующий класс пропускает при чтении. Для Commerz полезна гипотеза stable type id + codec version + explicit unknown-type behavior. Это предложение на основании исходника, не требование использовать Lunar.
3. **Ledger и no-oversell — разные гарантии.** Append-only история объясняет произошедшее. Admission protocol должен атомарно сериализовать обещания относительно общей ёмкости. Проверять это нужно на overlapping stock pools, bundles и callback после expiry, а не только на одиночном decrementation.
4. **Idempotency купона и общий usage limit — две задачи.** Lunar показывает защиту repeated draft checkout от повторного потребления; Bagisto показывает повторную проверку лимита под lock внутри order transaction. Одно не заменяет другое.
5. **Подмена модели слабо композируется.** Официальный Concord/Bagisto contract допускает одного победителя; два plugins, заменяющих одну модель, конфликтуют. Кандидат для Commerz — typed registries/strategies и явные stage dependencies; host-controlled model replacement остаётся отдельным решением.
6. **Accepted order economics должны переживать изменения каталога и plugins.** Snapshot-поля в Lunar дают практический референс; Commerz ещё предстоит определить immutable financial revision, amendments и partial refund allocations. Наличие snapshot колонок не доказывает immutability.
7. **«Всё продаваемое» не имеет единой inventory semantics.** Physical units, license keys, appointment intervals, rentals и future subscription obligations требуют различных invariant owners. Общий Sellable/Offer/Order contract возможен; единый lifecycle на nullable columns и callbacks может скрыть ошибки.

## Предлагаемые варианты, которые пока не надо схлопывать в один

| Область | Варианты | Решающий эксперимент |
|---|---|---|
| Атрибуты | Typed JSON; typed EAV; structured references + JSON metadata | Один workload для hydrate/update/facets/migrations, с реальным планом SQL |
| Inventory storage | Balance + atomic journal; authoritative ledger + synchronous admission projection | Два checkout на последнюю единицу и replay всех transitions |
| Inventory mapping | Variant = inventory item как ограничение v1; отдельный demand mapping | Два sellables потребляют один компонент; bundle + component в корзине |
| Allocation timing | Allocate location при checkout; pool claim с поздним location allocation | Общий warehouse в двух pools, самовывоз, запрет split shipment |
| Promotions | Приоритет и явная совместимость; best-combination optimizer | Конфликтующие promotions, caps, free shipping, deterministic tie-break |
| Extensibility | Strategy/registry/stage graph; контролируемые model substitutions | Два независимых plugins и переупорядочение providers |
| Профиль пакета | Embedded retail; operational modular commerce; orchestration над внешними domain systems | Один и тот же order/refund/retry сценарий на выбранном профиле |

Каждый вариант должен иметь контрпример. Нельзя объявить решение «идеальным» по совпадению терминов с Shopify/Magento.

## Критические сценарии для следующей исследовательской итерации

- T-shirt: цвет/размер, отсутствующая комбинация, одна цена на channel, другая на customer group, локализованное описание и общая inventory identity.
- Набор: bundle содержит две детали; покупатель одновременно берёт набор и одну деталь отдельно; доступна последняя деталь.
- Два магазина продают остаток одного склада; один заказ приходит через POS, другой через web.
- Купон с лимитом 1 одновременно применяют два покупателя; первый повторяет checkout после ошибки оплаты.
- Оплата успешна после expiry; stock и coupon capacity уже заняты другим заказом.
- Отправлена часть заказа из двух locations, остаток отменён, цена и product options в каталоге изменились, выполнен partial refund.
- Платёжный/discount plugin удалён после покупки; order history и refund расчёт остаются читаемыми.
- ERP/WMS становится новым владельцем физических остатков: как переключить writer без бессрочного dual write.

## Ограничения, важные для Claude Code review

Ответы Perplexity содержат как первичные ссылки, так и собственные предложения. Пометки «verified» внутри них принадлежат Perplexity и не заменяют нашу проверку. Проверенные нами load-bearing claims вынесены отдельно.

DOM extraction потерял некоторые таблицы, формулы, inline citations и промежуточные заголовки. Особенно в ответе 04 у profiles B/C некоторые exclusions выглядят как обычные bullets вслед за scope. Нельзя механически превращать этот текст в requirements. Raw JSON сохранён без редактирования ответа; Markdown содержит предупреждение. Каждый returned source list содержит 10 ссылок, хотя в тексте встречается больше: полнота списка не гарантирована.

GitHub branch sources закреплены commit SHA. Официальные страницы документации прочитаны на указанную дату, но некоторые являются moving docs. Shopify public API не раскрывает частную физическую БД. Соседние пакеты и исходный локальный аудит анализируются другими частями corpus; этот файл не заменяет ecosystem-fit review.

Никакие migration, production данные или external account settings не изменялись. Пакеты-референсы не запускались; performance и concurrency claims Commerz пока проверяются проектированием сценариев, а не выполненными тестами.
