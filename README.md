# ModulY

CMS-agnostic ядро-загрузчик модулей на PHP (≥ 8.2): манифест, валидация, подпись, жизненный цикл, DI, миграции.
WordPress-плагин **ModulY** (Plugin Name: ModulY, Author: PHP FastY) с набором модулей живёт в отдельном монорепо [phpfasty/wpmodules](https://github.com/phpfasty/wpmodules) и ставит это ядро через Composer.

## Что внутри (v0.1)

- `Manifest` — `module.json` + [schema/module-1.json](schema/module-1.json), валидация, `DependencyResolver` (core/php/host/modules, топосорт, циклы).
- `Package` — `SafeZipExtractor` (карантин, zip-slip, symlink, zip-bomb, whitelist), `Installer` (schema → подпись → зависимости → статический скан → атомарный move в `<modules>/<id>/<version>/`), `Packer`.
- `Signature` — ed25519 (`SIGNATURE`: sha256 всех файлов + подпись canonical JSON), `Keyring` с отзывом ключей.
- `Scan` — статический скан PHP модуля до первого `include`.
- `Container`, `Kernel` — DI со scope по модулю, lifecycle (boot/activate/deactivate), safe mode, `failed`-статус.
- `Migration` — forward-only миграции, lock, батчи, `MigrationGuard`, `FieldValue` (aliases, default + log).
- `bin/moduly` — keygen / sign / pack / verify для издателя.

## Разработка

```bash
composer install
composer test      # phpunit (unit + architecture)
composer phpstan   # level max
composer phpcs     # PSR-12
```

Архитектурный тест запрещает вызовы WordPress-функций в `src/`.
Решения: [docs/adr](docs/adr/). Релизы: теги `core-vX.Y.Z` → zip-артефакт.

## Лицензия

AGPL-3.0-or-later © PHP FastY (https://github.com/phpfasty). См. [LICENSE](LICENSE).
