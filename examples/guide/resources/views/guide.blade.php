<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipeasy · PHP Entity Guide</title>
    <link rel="stylesheet" href="{{ asset('guide.css') }}">
</head>
<body>
    <main class="wrap">

        <header class="hero">
            <h1>Shipeasy · PHP Entity Guide</h1>
            <p class="subtitle">
                One card per Shipeasy entity — feature flags, dynamic configs, A/B
                experiments, kill switches, events, i18n labels, and structured error
                reporting — each with the exact PHP SDK call you'd write.
            </p>
            <div class="banner">
                <span aria-hidden="true">⚠</span>
                <span>
                    <strong>SDK not wired yet</strong> — every value below is a placeholder.
                    Install <code>shipeasy/shipeasy</code> and replace the TODOs to make them live.
                </span>
            </div>
        </header>

        <section class="cards">
            @foreach ($entities as $e)
                @php
                    // accent-tinted soft background (~14% alpha) for the pills
                    $hex = ltrim($e['accent'], '#');
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                    $soft = "rgba($r, $g, $b, 0.14)";
                @endphp
                <article class="card">
                    <div class="card-top">
                        <span class="pill" style="background: {{ $soft }}; color: {{ $e['accent'] }};">
                            {{ $e['type'] }}
                        </span>
                        <span class="value-pill" style="background: {{ $soft }}; color: {{ $e['accent'] }};" title="{{ $e['value'] }}">
                            {{ $e['value'] }}
                        </span>
                    </div>

                    <h2>{{ $e['key'] }}</h2>
                    <p class="desc">{{ $e['description'] }}</p>

                    <div class="codeblock">
                        <code><span class="todo">// TODO: once shipeasy/shipeasy is installed</span>{{ $e['call'] }}</code>
                    </div>

                    <div class="meta">{{ $e['meta'] }}</div>
                </article>
            @endforeach
        </section>

        <footer class="footer">
            <div class="run">composer install &amp;&amp; cp .env.example .env &amp;&amp; php artisan key:generate &amp;&amp; php artisan serve
# → http://127.0.0.1:8000

# Next step: composer require shipeasy/shipeasy and replace each // TODO above.</div>
            <p>
                Shipeasy PHP SDK example · Docs at
                <a href="https://docs.shipeasy.ai" target="_blank" rel="noopener">https://docs.shipeasy.ai</a>
            </p>
        </footer>

    </main>
</body>
</html>
