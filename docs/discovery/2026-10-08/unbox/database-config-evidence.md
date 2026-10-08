# Unbox: безопасные excerpts конфигурации базы данных

Captured: 2026-10-08T14:27:06.167979+00:00. HEAD: `4e0d338c5316bb5e216239c4bfb0b62ce60c5a88`.

Это дополнение содержит только выбранные строки tracked файлов. Credentials, `.env`, resolved Compose config и runtime DB не читались. SHA-256 относится к полному исходному tracked файлу; файл здесь не воспроизведён целиком. Поддержка драйвера в Laravel config и заявленная инфраструктура не доказывают фактический runtime или прохождение migrations на каждом engine.

## config/database.php

SHA-256: `d6e7ca26cd95012304342e346c00f0370ee6fd10a2cdb374b267ad647ec36501`. Всего строк: 184.

```text
  21 |     'default' => env('DB_CONNECTION', 'sqlite'),
  36 |         'sqlite' => [
  37 |             'driver' => 'sqlite',
  47 |         'mysql' => [
  48 |             'driver' => 'mysql',
  50 |             'host' => env('DB_HOST', '127.0.0.1'),
  51 |             'port' => env('DB_PORT', '3306'),
  67 |         'mariadb' => [
  68 |             'driver' => 'mariadb',
  87 |         'pgsql' => [
  88 |             'driver' => 'pgsql',
  90 |             'host' => env('DB_HOST', '127.0.0.1'),
  91 |             'port' => env('DB_PORT', '5432'),
 102 |         'sqlsrv' => [
 103 |             'driver' => 'sqlsrv',
```

## docker-compose.yml

SHA-256: `cf765de2472e57b077ebe793377b1f4988472dafa2b4befc8c34f3b25268f958`. Всего строк: 238.

```text
  42 |       DB_CONNECTION: ${DB_CONNECTION:-mysql}
  43 |       DB_HOST: ${DB_HOST:-mysql}
  44 |       DB_PORT: ${DB_PORT:-3306}
  93 |       DB_CONNECTION: ${DB_CONNECTION:-mysql}
  94 |       DB_HOST: ${DB_HOST:-mysql}
  95 |       DB_PORT: ${DB_PORT:-3306}
 141 |       DB_CONNECTION: ${DB_CONNECTION:-mysql}
 142 |       DB_HOST: ${DB_HOST:-mysql}
 143 |       DB_PORT: ${DB_PORT:-3306}
 185 |       DB_CONNECTION: ${DB_CONNECTION:-mysql}
 186 |       DB_HOST: ${DB_HOST:-mysql}
 187 |       DB_PORT: ${DB_PORT:-3306}
 210 |   mysql:
 211 |     image: mysql:8.4
 212 |     container_name: unbox_mysql
 220 |       - "${MYSQL_PORT:-3306}:3306"
 222 |       - mysql_data:/var/lib/mysql
```

## README.md

SHA-256: `25aa31fd41d72731cfb02096cec863ed912c4f49128c7a700cbab936c6efd8ca`. Всего строк: 1547.

```text
  25 | -   **База данных**: MySQL 8.0+, PostgreSQL 13+, или SQLite (для разработки)
  77 | -   `DB_CONNECTION` - тип базы данных
  84 | Для SQLite (разработка):
  87 | touch database/database.sqlite
  90 | Для MySQL/PostgreSQL создайте базу данных и укажите параметры в `.env`.
```

## docker/README.md

SHA-256: `6369f8637101b36741512769f920a8f38545bf5f63ba80112940a173775b2a2b`. Всего строк: 594.

```text
   3 | Этот проект использует Docker с образами `serversideup/php:8.4-fpm-nginx` для PHP и Nginx, MySQL 8.4 для базы данных, Vite для сборки фронтенда, Laravel Reverb для WebSocket соединений, Task Scheduler для выполнения задач по расписанию, и Queue Worker для обработки очередей.
  21 | DB_CONNECTION=mysql
  22 | DB_HOST=mysql
  23 | DB_PORT=3306
```

## Dockerfile

SHA-256: `8f3ebb4bc23d3552af2403c7f817eb349c0873c36864313cf3b6f80fcf03b8bf`. Всего строк: 33.

```text
   1 | FROM serversideup/php:8.4-fpm-nginx
```

## Что можно утверждать

- В tracked Docker Compose объявлен сервис `mysql` с образом `mysql:8.4`; app и сопутствующие PHP services получают default `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`. Значения переопределяются окружением.
- В Laravel `config/database.php` fallback для отсутствующего `DB_CONNECTION` — `sqlite`. Также объявлены конфигурации mysql, mariadb, pgsql, sqlsrv.
- README заявляет MySQL 8.0+, PostgreSQL 13+ либо SQLite для разработки; docker/README конкретизирует MySQL 8.4. Это документированная совместимость, не проверенный здесь результат.
- Dockerfile закрепляет PHP image `serversideup/php:8.4-fpm-nginx`; эта строка сама по себе не доказывает runtime database engine или набор установленных PDO extensions.
- Фактически запущенная СУБД неизвестна: окружение, config cache и live connections не проверялись.
