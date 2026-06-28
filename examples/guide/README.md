# Shipeasy · PHP Entity Guide (example app)

A single-page **Laravel 11** app that reads like a "big guide document": one
styled card per Shipeasy entity — feature flag, dynamic config, A/B experiment,
kill switch, event/metric, i18n label, and structured error reporting (`see()`).

It runs with `php artisan serve` and renders with **no external services and no
database**. The `/` route never touches a DB and makes no network calls.

## ⚠ SDK not wired yet

This example deliberately does **not** depend on `shipeasy/shipeasy`. Every value
shown on the page is a hardcoded placeholder passed from `routes/web.php` to the
Blade view. For each entity:

- the **real SDK call** is preserved as a `// TODO: once shipeasy/shipeasy is
  installed` block in `routes/web.php`, and
- that same call is rendered verbatim as a code block on the page.

So you can read the whole entity model end-to-end before installing anything.

## Run

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Then open <http://127.0.0.1:8000>.

> Requires PHP 8.2+ (`composer.json` pins `laravel/framework: ^11.0`).

## Next step — make it live

```bash
composer require shipeasy/shipeasy
```

Then in `routes/web.php`:

1. Construct one client near the top of the route (the commented block shows how):

   ```php
   use Shipeasy\Client;

   $c = new Client([
       'serverKey' => env('SHIPEASY_SERVER_KEY', ''),
       'env'       => 'production',
   ]);
   ```

2. Replace each `// TODO` placeholder with its real call, e.g.:

   ```php
   $new_checkout = $c->getFlag('new_checkout', ['user_id' => 'u_123']);
   $billing_copy = $c->getConfig('billing_copy');
   $r           = $c->getExperiment('checkout_button', ['user_id' => 'u_123'], ['color' => '#888', 'label' => 'Buy']);
   ```

   and feed the live values into the `$entities` array.

3. Set `SHIPEASY_SERVER_KEY` in `.env`.

## The entities (in order)

| # | Entity | Key | Real call |
|---|--------|-----|-----------|
| 1 | Feature Flag | `new_checkout` | `$c->getFlag('new_checkout', ['user_id' => 'u_123'])` |
| 2 | Dynamic Config | `billing_copy` | `$c->getConfig('billing_copy')` |
| 3 | A/B Experiment | `checkout_button` | `$c->getExperiment('checkout_button', ['user_id' => 'u_123'], [...])` |
| 4 | Kill Switch | `payments_paused` | `$c->evaluate([...])['killswitches']['payments_paused']` |
| 5 | Event / Metric | `checkout_completed` | `$c->track('u_123', 'checkout_completed', [...])` |
| 6 | i18n Label | `hero.title` | `t('hero.title', ['name' => 'Sam'])` *(PHP i18n is a follow-up)* |
| 7 | Error Reporting | `see()` | `Shipeasy::see($e)->causesThe('checkout')->to('use cached prices')->extras([...])` |

## File tree

```
examples/guide/
├── artisan
├── composer.json
├── .env.example
├── .gitignore
├── README.md
├── app/
│   ├── Http/Controllers/
│   └── Providers/AppServiceProvider.php
├── bootstrap/
│   ├── app.php
│   ├── providers.php
│   └── cache/
├── config/
│   ├── app.php
│   └── view.php
├── public/
│   ├── index.php
│   └── guide.css
├── resources/views/guide.blade.php
├── routes/web.php
└── storage/            (framework caches, sessions, views, logs)
```

Docs: <https://docs.shipeasy.ai>
