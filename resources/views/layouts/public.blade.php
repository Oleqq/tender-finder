<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title') — TenderFinder</title>
    <script>try { var savedTheme = localStorage.getItem('tf-public-theme'); if (savedTheme === 'light' || savedTheme === 'dark') document.documentElement.dataset.theme = savedTheme; } catch (_) {}</script>
    @include('partials.public-styles')
</head>
<body>
<a class="skip-link" href="#main-content">Перейти к содержимому</a>
<header class="site-header">
    <div class="shell">
        <div class="header-row">
            <a class="brand" href="/info" aria-label="TenderFinder — о сервисе"><span class="brand-mark" aria-hidden="true">TF</span>TenderFinder</a>
            <div class="header-actions">
                <a class="button button-secondary" href="/">В приложение</a>
                <button class="theme-toggle" type="button" data-theme-toggle hidden aria-label="Включить тёмную тему" aria-pressed="false">
                    <svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"/></svg>
                    <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
                </button>
            </div>
        </div>
        <nav class="primary-nav" aria-label="О сервисе">
            @foreach (['/info' => 'О сервисе', '/pricing' => 'Тарифы', '/requisites' => 'Реквизиты'] as $href => $label)
                <a href="{{ $href }}" @if(request()->getPathInfo() === $href) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>
<main class="shell" id="main-content" tabindex="-1">@yield('content')</main>
<footer class="site-footer">
    <div class="shell footer-row">
        <div><strong>{{ config('company.short_name') }}</strong><p>ИНН {{ config('company.inn') }} · ОГРНИП {{ config('company.ogrnip') }}</p></div>
        <nav class="footer-links" aria-label="Полезные ссылки"><a href="/">В приложение</a><a href="/info#support">Связаться с поддержкой</a><a href="/privacy">Политика ПДн</a></nav>
    </div>
</footer>
<script>
(function () {
    var button = document.querySelector('[data-theme-toggle]');
    var system = window.matchMedia('(prefers-color-scheme: dark)');
    function update() {
        var mode = document.documentElement.dataset.theme;
        var dark = mode === 'dark' || (!mode && system.matches);
        button.setAttribute('aria-pressed', String(dark));
        button.setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
        button.title = dark ? 'Светлая тема' : 'Тёмная тема';
    }
    button.hidden = false;
    button.addEventListener('click', function () {
        var theme = button.getAttribute('aria-pressed') === 'true' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try { localStorage.setItem('tf-public-theme', theme); } catch (_) {}
        update();
    });
    if (system.addEventListener) system.addEventListener('change', update);
    update();
    if (window.matchMedia('(min-width: 860px)').matches) {
        document.querySelectorAll('[data-desktop-open]').forEach(function (item) { item.open = true; });
    }
})();
</script>
</body>
</html>
