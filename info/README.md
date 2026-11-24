# Rush Backend

Laravel 11 API with 3-tier layout matching NAD style:

- `app/Presentation` — HTTP controllers
- `app/Business` — services, auth factory/providers, exceptions
- `app/Data/Models` — Eloquent models

## Quick start

```bash
cd Backend
composer install
cp .env.example .env   # if needed
php artisan key:generate
# Configure PostgreSQL in .env then:
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8010
```

Open Swagger: http://127.0.0.1:8010/docs

## Tests

```bash
php artisan test
```

## Auth design

`AuthService::register(...)` / `AuthService::login(...)` delegate to
`AuthProviderFactory` which returns `GoogleAuthProvider`, `AppleAuthProvider`,
or `PhoneAuthProvider`. Preferred language defaults to **en** for notification localization.
