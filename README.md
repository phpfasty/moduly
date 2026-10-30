# ModulY

CMS-agnostic ядро-загрузчик модулей на PHP (≥ 8.2): манифест, валидация, подпись, жизненный цикл, DI, миграции.
Адаптер для WordPress (плагин с набором модулей) живёт отдельно: [phpfasty/wpmodules](https://github.com/phpfasty/wpmodules).

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
