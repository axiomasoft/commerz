# Commerz — исследовательское досье, 8 октября 2026

Здесь собраны замысел владельца, его существующие проекты, GitHub-звёзды, внешние источники, Perplexity, критический разбор старого аудита и наши собственные гипотезы. **Это первая исследовательская волна, не утверждённая архитектура и не реализация пакета.**

## Начать отсюда

| Материал | Что даёт |
|---|---|
| [Бриф](brief.md) | Исходные требования и добавленная задача Unbox |
| [Синтез и гипотезы](design-hypotheses.md) | Границы модулей, inventory, coupons, plugins, инварианты и возражения |
| [Analysis Unbox](analysis-unbox.md) | Самодостаточный разбор собственной модели каталога и торговли |
| [Сравнение схем свойств](attribute-storage-options.md) | Явная схема, slots, option relations, EAV, JSON, query projections |
| [Логическая модель](logical-schema-candidate.md) | Кандидатные сущности, связи и незавершённые решения |
| [Эксперименты и следующие вопросы](research-roadmap.md) | Как выбирать между вариантами по измерениям и контрпримерам |

## Исследовательские потоки

| Поток | Основной отчёт | Подробные свидетельства |
|---|---|---|
| Локальная экосистема | [Chatom / Vaulter / AzGuard](ecosystem-conventions.md) | [Manifest ревизий и выдержек](ecosystem/evidence-manifest.json), локальные evidence MD |
| GitHub stars через gh | [Отобранные референсы](github-references.md) | [Полный каталог 715 stars](github/all-starred-catalog.md), [metadata](github/starred-repositories.json), [collection manifest](github/collection-manifest.json), шесть `*-source-review.md` |
| Shopify / Adobe / Shopware / commercetools | [Внешние платформы](external-platforms.md) | [Manifest](external/source-manifest.json), восемь локальных source snapshots |
| Perplexity через Shell | [Обзор исследования](perplexity-research.md) | [13 проверенных выводов](perplexity/verified-findings.md), четыре raw JSON/MD, prompts, provenance, snapshots |
| Существующий аудит ChatGPT | [Критический разбор](chatgpt-audit-review.md) | [Воспроизведение 16 проверок](audit/reproduction-manifest.json), [76 дополнительных сценариев](audit/scenario-lab.md) |
| Unbox | [Analysis Unbox](analysis-unbox.md) | [Словарь фактической схемы](unbox/schema-dictionary.md), [manifest исходников](unbox/source-manifest.json), [независимое сравнение](unbox/alternative-design-review.md) |
| Термины | [Рабочий словарь](CONTEXT.md) | Определения без реализации |
| Независимая проверка синтеза | [Review](audit/synthesis-review.md) | Находки, уточнения и статус исправлений |
| Проверка корпуса | [Результат проверок](corpus-validation.json) | [Индекс артефактов с SHA-256](artifact-index.json); проверяются файлы и свидетельства, не runtime commerce |

## Что фактически сделано

- Через `gh` прочитан доступный API список **715 уникальных звёзд** аккаунта `academici`; проверены последняя страница из 15 записей и пустая следующая. Metadata snapshot нормализован, не является byte-for-byte копией первоначального CLI stdout.
- Отобраны **284 широких PHP/Laravel-кандидата**, среди них **47 тематических референсов**. Шесть проектов изучены глубже по исходникам: Lunar, Bazar, Vanilo, Bagisto, Sylius, Shopper. Остальные в очереди не помечаются как проаудированные.
- Выполнены **4 Perplexity Deep Research** через уже настроенный shell client; сохранены ответы, запросы и provenance. Отдельно проверены ключевые утверждения. У raw extraction есть потери таблиц/формул, описанные в отчёте.
- По соседним пакетам зафиксированы конкретные файлы, версии и изменённость рабочего дерева. Chatom изменялся другим процессом во время чтения; в manifest сохранены наблюдавшиеся ревизии и hashes.
- Unbox разобран по исходному коду, миграциям и связям: сохранены snapshots 143 файлов и словарь 38 таблиц. Ограничение «шесть свойств» — исходное предметное предположение владельца; найденная реализация не приравнивается автоматически к шести физическим колонкам.
- Повторены исходные 16 иллюстративных semantic checks; их прохождение не подтверждает работающий Commerz, конкурентный checkout или эксплуатационные гарантии.
- Созданы новые варианты архитектуры, схема для обсуждения и сценарии опровержения. Исходные аудиты сохранены неизменными.

## Как Claude Code проверить это досье без сканирования других проектов

1. Прочитать brief, design-hypotheses, attribute-storage-options и logical-schema-candidate.
2. Прочитать analysis-unbox, затем при необходимости локальные numbered snapshots в `unbox/`. Пути исходного Unbox служат provenance; подтверждающий материал находится здесь.
3. Проверить выбранные факты из ecosystem report по локальным evidence-файлам и manifest. Не переносить рекомендации обратно в соседние пакеты автоматически.
4. Для PHP-commerce фактов использовать `github/*-source-review.md` и commit permalinks. Для дополнительной локальной проверки Lunar/Bagisto/Sylius есть snapshots в `perplexity/sources/`; исходники Magento/Shopware — в `external/sources/`.
5. Отделять primary-code facts от metadata-кандидатов, raw Perplexity claims и наших proposals. Совпадение нескольких LLM-ответов не является независимым подтверждением.
6. Пытаться опровергнуть основные варианты сценариями из research-roadmap и audit/scenario-lab. Не ставить «архитектура подтверждена» за одно прочтение документа.

Рекомендуемый формат результата проверки: `finding → факт/гипотеза → локальное свидетельство → контрпример → последствия → альтернативы → что нужно измерить`. Отдельно указать новые требования, которые нельзя вывести из текущего брифа.

## Принципиальные открытые решения

Не утверждены: физическая БД, ID policy, префикс таблиц, версии runtime, storage свойств, tenancy strategy, состав v1, количество Composer-пакетов и public API. Есть сильные кандидаты и материал для выбора. Большой объём исследования не превращает каждую изученную возможность в обязательную функцию пакета.
