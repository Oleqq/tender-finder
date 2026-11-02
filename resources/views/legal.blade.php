<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>{{ $document['title'] ?? 'О сервисе и документы' }} — TenderFinder</title>
    <style>
        :root{color-scheme:light dark;--bg:#f4f6fb;--surface:#fff;--text:#182440;--muted:#52627a;--line:#dae1ee;--accent:#3b50dd}
        @media(prefers-color-scheme:dark){:root{--bg:#101318;--surface:#191e27;--text:#edf0f7;--muted:#b1bdd1;--line:#343e50;--accent:#a9b5ff}}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.75 system-ui,-apple-system,sans-serif;overflow-wrap:anywhere}
        a{color:var(--accent);text-underline-offset:3px}a:focus-visible{outline:3px solid var(--accent);outline-offset:4px}
        .wrap{width:min(960px,100%);margin:auto;padding:24px max(16px,env(safe-area-inset-right)) 48px max(16px,env(safe-area-inset-left))}
        nav{display:flex;flex-wrap:wrap;gap:10px 20px;margin:16px 0 24px}nav a[aria-current=page]{font-weight:750}
        .brand{font-weight:800;letter-spacing:-.04em;font-size:25px;text-decoration:none}.paper{background:var(--surface);border:1px solid var(--line);border-radius:24px;padding:clamp(18px,4vw,44px)}
        h1{font-size:clamp(25px,5vw,38px);line-height:1.25;margin:0 0 16px;letter-spacing:-.03em}h2{font-size:20px;line-height:1.4;margin:30px 0 12px}p{white-space:pre-line;margin:16px 0}.muted,footer{color:var(--muted);font-size:14px}
        .table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:12px;margin:24px 0}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:12px;border-bottom:1px solid var(--line);vertical-align:top;min-width:75px;white-space:pre-line}tr:last-child td{border-bottom:0}
        .download{display:inline-block;padding:10px 16px;border:1px solid var(--line);border-radius:12px;margin-bottom:12px}footer{margin-top:24px}ul{padding-left:22px}.contacts{scroll-margin-top:20px}
        .plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:16px}.plan{border:1px solid var(--line);border-radius:16px;padding:16px}.plan h3{margin:0;font-size:22px}.plan p{margin:4px 0;font-size:14px;color:var(--muted)}dl{margin:12px 0 0}dl div{display:flex;justify-content:space-between;gap:12px}dd{margin:0;font-weight:700;white-space:nowrap}
    </style>
</head>
<body>
<main class="wrap">
    <a class="brand" href="/info">TenderFinder</a>
    <nav aria-label="Информация и документы">
        @foreach (['/info'=>'О сервисе', '/pricing'=>'Тарифы', '/terms'=>'Соглашение', '/offer'=>'Оферта', '/privacy'=>'Политика ПДн', '/consent'=>'Согласие на ПДн', '/requisites'=>'Реквизиты'] as $href => $label)
            <a href="{{ $href }}" @if(request()->getPathInfo() === $href) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <article class="paper">
    @if ($document)
        <h1>{{ $document['title'] }}</h1>
        <p class="muted">Редакция размещена {{ \Illuminate\Support\Carbon::parse($document['edition'])->format('d.m.Y') }}. Версия: {{ $document['version'] }}.</p>
        <a class="download" href="{{ route('legal.download', ['document' => $document['type']]) }}">Скачать оригинал Word (.docx)</a>
        @foreach ($document['blocks'] as $block)
            @if ($block['type'] === 'table')
                <div class="table-wrap" role="region" aria-label="Таблица документа" tabindex="0"><table>
                    @foreach ($block['rows'] as $row)
                        <tr>@foreach ($row as $cell) @if($loop->parent->first)<th scope="col">{{ $cell }}</th>@else<td>{{ $cell }}</td>@endif @endforeach</tr>
                    @endforeach
                </table></div>
            @elseif (preg_match('/^\d+\.\s+[А-ЯЁ\s()0-9.]+$/u', $block['text']))
                <h2>{{ $block['text'] }}</h2>
            @else
                <p>{{ $block['text'] }}</p>
            @endif
        @endforeach
    @else
        <h1>О сервисе TenderFinder</h1>
        <p>TenderFinder — цифровой сервис мониторинга закупок и подготовки заявок. Он помогает находить тендеры по условиям пользователя, получать уведомления в Telegram, вести документы, задачи и расчёты по заявкам.</p>
        <p>Бот: <a href="https://t.me/RUTenderFinder_bot">@RUTenderFinder_bot</a>. Услуга предоставляется в электронной форме; физическая доставка не требуется. Доступ используется через Telegram-аккаунт пользователя.</p>
        <h2>Тарифы доступа</h2>
        <div class="plans" aria-label="Цены подписок">
            @foreach($offers as $offer)
                <section class="plan"><h3>{{ $offer['name'] }}</h3><p>До {{ $offer['limit'] }} активных мониторингов</p><dl>
                    @foreach($offer['prices'] as $months => $price)<div><dt>{{ [1 => '1 месяц', 3 => '3 месяца', 12 => '12 месяцев'][$months] }}</dt><dd>{{ number_format($price / 100, 0, '', ' ') }} ₽</dd></div>@endforeach
                </dl></section>
            @endforeach
        </div>
        <p>Указана полная стоимость выбранного срока одним платежом. Скидки на 3 месяца — от 5%, на 12 месяцев — от 10%. <a href="/pricing">Состав тарифов</a>.</p>
        <h2>Получение доступа</h2>
        <p>При первом входе после подтверждения документов предоставляется однократный пробный период на 72 часа. Покупка и приём реальных платежей пока закрыты. Порядок активации оплаченного доступа будет доступен до оформления заказа после подключения платёжной системы.</p>
        <h2>Документы</h2>
        <ul><li><a href="/terms">Пользовательское соглашение</a></li><li><a href="/offer">Публичная оферта</a></li><li><a href="/privacy">Политика обработки персональных данных</a></li><li><a href="/consent">Согласие на обработку персональных данных</a></li></ul>
        <h2 id="support" class="contacts">Контакты и поддержка</h2>
        <p>{{ $company['short_name'] }}<br>ИНН: {{ $company['inn'] }}<br>ОГРНИП: {{ $company['ogrnip'] }}<br>Адрес: {{ $company['legal_address'] }}</p>
        <p>E-mail: <a href="mailto:{{ $company['support_email'] }}">{{ $company['support_email'] }}</a>.<br>В боте: /support — общие вопросы, /paysupport — платежи и возвраты. В приложении: Профиль → Мои обращения.</p>
        <p><a href="/requisites">Полные банковские реквизиты</a>. Условия возврата изложены в <a href="/offer">публичной оферте</a>.</p>
    @endif
    </article>
    <footer><a href="/">В приложение</a> · <a href="/info#support">Связаться с поддержкой</a><p>{{ $company['short_name'] }} · ИНН {{ $company['inn'] }}</p></footer>
</main>
</body>
</html>
