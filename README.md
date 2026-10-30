# ModulY

CMS-agnostic ядро-загрузчик модулей на PHP (≥ 8.2): манифест, валидация, подпись, жизненный цикл, DI, миграции.
WordPress-плагин **ModulY** (Plugin Name: ModulY, Author: PHP FastY) с набором модулей живёт в отдельном монорепо [phpfasty/wpmodules](https://github.com/phpfasty/wpmodules) и ставит это ядро через Composer.

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
