<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shipeasy · PHP Entity Guide
|--------------------------------------------------------------------------
|
| A single page that reads like a "big guide document": one styled card per
| Shipeasy entity. The SDK is NOT installed yet, so every value below is a
| hardcoded placeholder. The REAL SDK call for each entity is preserved as a
| `// TODO: once shipeasy/shipeasy is installed` block AND rendered verbatim
| on the page as a code block.
|
| This route touches NO database and makes NO network calls — it boots and
| renders standalone with `php artisan serve`.
|
| ----------------------------------------------------------------------------
| TODO: once shipeasy/shipeasy is installed, configure once at boot, then bind
| a user per request and replace each placeholder below with the live call.
|
|   use function Shipeasy\configure;
|   use Shipeasy\Client;
|
|   // once, at startup (e.g. a service provider):
|   configure(env('SHIPEASY_SERVER_KEY', ''), null, ['env' => 'production']);
|
|   // per request — bind the user, then call without a user argument:
|   $c = new Client(['user_id' => 'u_123']);
| ----------------------------------------------------------------------------
|
*/

Route::get('/', function () {

    // 1) FEATURE FLAG — a boolean on/off switch with targeting + % rollout.
    // TODO: once shipeasy/shipeasy is installed:
    //   $on = $c->getFlag('new_checkout', ['user_id' => 'u_123']);
    $new_checkout = true;        // placeholder
    $new_checkout_reason = 'RULE_MATCH';

    // 2) DYNAMIC CONFIG — a typed JSON blob you change without deploying.
    // TODO: once shipeasy/shipeasy is installed:
    //   $cfg = $c->getConfig('billing_copy');
    $billing_copy = [
        'headline' => 'Welcome back 👋',
        'cta'      => 'Upgrade to Pro',
    ];                           // placeholder

    // 3) A/B EXPERIMENT — splits users into variants and measures a metric.
    // TODO: once shipeasy/shipeasy is installed:
    //   $r = $c->getExperiment('checkout_button', ['user_id' => 'u_123'], ['color' => '#888', 'label' => 'Buy']);
    $checkout_button = [
        'inExperiment' => true,
        'group'        => 'treatment',
        'params'       => ['color' => '#34d399', 'label' => 'Buy now'],
    ];                           // placeholder

    // 4) KILL SWITCH — an operational off-switch shipped alongside flags.
    // TODO: once shipeasy/shipeasy is installed:
    //   $boot   = $c->evaluate(['user_id' => 'u_123']);
    //   $paused = $boot['killswitches']['payments_paused'];
    $payments_paused = false;    // placeholder — payments live

    // 5) EVENT / METRIC — fire-and-forget events that power metrics + dashboards.
    // TODO: once shipeasy/shipeasy is installed:
    //   $c->track('u_123', 'checkout_completed', ['revenue' => 49.99, 'plan' => 'pro']);
    $last_event = [
        'name'  => 'checkout_completed',
        'props' => ['revenue' => 49.99, 'plan' => 'pro'],
    ];                           // placeholder — "last event queued"

    // 6) I18N LABEL — server-managed copy you translate + publish, no redeploy.
    // NOTE: i18n for the PHP SDK ships as a follow-up; shown here for completeness.
    // TODO (illustrative): t('hero.title', ['name' => 'Sam']);
    $hero_title = 'Ship features, not stress';   // placeholder

    // 7) ERROR REPORTING — see() — structured reports of the product consequence.
    // TODO: once shipeasy/shipeasy is installed:
    //   try {
    //       submitOrder($o);
    //   } catch (\Throwable $e) {
    //       Shipeasy::see($e)
    //           ->causesThe('checkout')
    //           ->to('use cached prices')
    //           ->extras(['order_id' => $o->id]);
    //   }
    $issues_reported = 0;        // placeholder — "0 issues reported this session"

    // ------------------------------------------------------------------
    // Build the ordered entity list passed to the Blade view. Each entry is
    // self-describing: accent colour, type label, key, human description, the
    // rendered placeholder value, and the SDK call shown verbatim on the page.
    // ------------------------------------------------------------------
    $entities = [
        [
            'accent'      => '#34d399',
            'type'        => 'Feature Flag',
            'key'         => 'new_checkout',
            'value'       => $new_checkout ? 'true' : 'false',
            'description' => 'A boolean on/off switch with targeting rules + percentage rollout.',
            'call'        => "\$on = \$c->getFlag('new_checkout', ['user_id' => 'u_123']);",
            'meta'        => "reason: {$new_checkout_reason}  ·  evaluated for user_id u_123",
        ],
        [
            'accent'      => '#60a5fa',
            'type'        => 'Dynamic Config',
            'key'         => 'billing_copy',
            'value'       => sprintf(
                "['headline' => '%s', 'cta' => '%s']",
                $billing_copy['headline'],
                $billing_copy['cta'],
            ),
            'description' => 'A typed JSON blob you change without deploying.',
            'call'        => "\$cfg = \$c->getConfig('billing_copy');",
            'meta'        => 'typed JSON · same value across all envs unless targeted',
        ],
        [
            'accent'      => '#c084fc',
            'type'        => 'A/B Experiment',
            'key'         => 'checkout_button',
            'value'       => sprintf(
                "group: %s  ·  ['color' => '%s', 'label' => '%s']",
                $checkout_button['group'],
                $checkout_button['params']['color'],
                $checkout_button['params']['label'],
            ),
            'description' => 'Splits users into variants and measures a metric.',
            'call'        => "\$r = \$c->getExperiment('checkout_button', ['user_id' => 'u_123'], ['color' => '#888', 'label' => 'Buy']);",
            'meta'        => 'inExperiment: '.($checkout_button['inExperiment'] ? 'true' : 'false').' · exposure logged on read',
        ],
        [
            'accent'      => '#f87171',
            'type'        => 'Kill Switch',
            'key'         => 'payments_paused',
            'value'       => $payments_paused ? 'true (PAUSED)' : 'false (payments live)',
            'description' => 'An operational off-switch shipped alongside flags — flip it to disable a subsystem during an incident.',
            'call'        => "\$boot = \$c->evaluate(['user_id' => 'u_123']);\n\$paused = \$boot['killswitches']['payments_paused'];",
            'meta'        => 'rides the same KV blob as flags · flip from the dashboard, no deploy',
        ],
        [
            'accent'      => '#22d3ee',
            'type'        => 'Event / Metric',
            'key'         => $last_event['name'],
            'value'       => sprintf(
                "['revenue' => %s, 'plan' => '%s']",
                $last_event['props']['revenue'],
                $last_event['props']['plan'],
            ),
            'description' => 'Fire-and-forget events that power experiment metrics + dashboards.',
            'call'        => "\$c->track('u_123', 'checkout_completed', ['revenue' => 49.99, 'plan' => 'pro']);",
            'meta'        => 'last event queued · non-blocking, flushed in the background',
        ],
        [
            'accent'      => '#fbbf24',
            'type'        => 'i18n Label',
            'key'         => 'hero.title',
            'value'       => $hero_title,
            'description' => 'Server-managed copy you translate + publish from the dashboard — no redeploy. (i18n for the PHP SDK ships as a follow-up; shown for completeness.)',
            'call'        => "t('hero.title', ['name' => 'Sam'])  // illustrative",
            'meta'        => 'follow-up for the PHP SDK · resolved server-side at render time',
        ],
        [
            'accent'      => '#f87171',
            'type'        => 'Error Reporting',
            'key'         => 'see()',
            'value'       => "{$issues_reported} issues reported this session",
            'description' => 'Structured error reports that document the product consequence, not just a stack trace.',
            'call'        => "try {\n    submitOrder(\$o);\n} catch (\\Throwable \$e) {\n    Shipeasy::see(\$e)\n        ->causesThe('checkout')\n        ->to('use cached prices')\n        ->extras(['order_id' => \$o->id]);\n}",
            'meta'        => 'reports the consequence (causesThe → to), not the stack trace',
        ],
    ];

    return view('guide', ['entities' => $entities]);
});
