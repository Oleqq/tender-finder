@extends('layouts.public')
@section('title', $document['title'] ?? 'О сервисе и документы')
@section('content')
@php
    $documents = ['/terms' => 'Пользовательское соглашение', '/offer' => 'Публичная оферта', '/privacy' => 'Политика обработки ПДн', '/consent' => 'Согласие на обработку ПДн'];
    $sections = $document ? collect($document['blocks'])->filter(fn ($block) => $block['type'] !== 'table' && preg_match('/^\d+\.\s+[А-ЯЁ\s()0-9.]+$/u', $block['text'])) : collect();
@endphp
@if ($document)
    <header class="hero">
        <span class="eyebrow">Документы TenderFinder</span>
        <h1>{{ $document['title'] }}</h1>
        <p class="meta">Редакция от {{ \Illuminate\Support\Carbon::parse($document['edition'])->format('d.m.Y') }}</p>
    </header>
    <div class="document-layout">
        <aside class="document-sidebar" aria-label="Навигация по документам">
            <details class="disclosure" data-desktop-open>
                <summary>Документы сервиса</summary>
                <nav class="document-menu" aria-label="Юридические документы">
                    @foreach ($documents as $href => $label)
                        <a href="{{ $href }}" @if(request()->getPathInfo() === $href) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </details>
            @if ($sections->isNotEmpty())
                <details class="disclosure contents" data-desktop-open>
                    <summary>Содержание документа</summary>
                    <nav aria-label="Содержание документа"><ol>
                        @foreach ($sections as $index => $section)<li><a href="#section-{{ $index }}">{{ $section['text'] }}</a></li>@endforeach
                    </ol></nav>
                </details>
            @endif
        </aside>
        <article class="paper" aria-label="Текст документа">
            <div class="document-toolbar">
                <a class="button button-secondary" href="{{ route('legal.download', ['document' => $document['type']]) }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M5 16v4h14v-4"/></svg>
                    Скачать оригинал Word (.docx)
                </a>
                <details class="version"><summary>Данные редакции</summary><p>Версия: {{ $document['version'] }}</p></details>
            </div>
            <div class="document-body">
                @foreach ($document['blocks'] as $block)
                    @if ($block['type'] === 'table')
                        <div class="table-wrap" role="region" aria-label="Таблица документа" tabindex="0"><table>
                            <caption class="sr-only">Таблица документа</caption>
                            <thead><tr>@foreach ($block['rows'][0] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead>
                            <tbody>@foreach (array_slice($block['rows'], 1) as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                        </table></div>
                        <p class="table-hint">Если таблица не помещается, прокрутите её в сторону.</p>
                    @elseif ($sections->has($loop->index))
                        <h2 id="section-{{ $loop->index }}">{{ $block['text'] }}</h2>
                    @else
                        <p>{{ $block['text'] }}</p>
                    @endif
                @endforeach
            </div>
            <nav class="reading-end" aria-label="После прочтения"><a href="#main-content">К началу документа ↑</a><a href="/info#support">Связаться с поддержкой</a></nav>
        </article>
    </div>
@else
    <header class="hero">
        <span class="eyebrow">О сервисе</span>
        <h1>О сервисе TenderFinder</h1>
        <p>TenderFinder — цифровой сервис мониторинга закупок и подготовки заявок. Он помогает находить тендеры по условиям пользователя, получать уведомления в Telegram, вести документы, задачи и расчёты по заявкам.</p>
    </header>
    <div class="info-grid">
        <div class="info-main">
            <section class="section">
                <h2>Тарифы доступа</h2>
                <div class="plans" aria-label="Цены подписок">
                    @foreach ($offers as $code => $offer)
                        <section class="plan {{ $code === 'pro' ? 'plan-highlight' : '' }}"><h3>{{ $offer['name'] }}</h3><p>До {{ $offer['limit'] }} активных мониторингов</p><dl>
                            @foreach ($offer['prices'] as $months => $price)<div><dt>{{ [1 => '1 месяц', 3 => '3 месяца', 12 => '12 месяцев'][$months] }}</dt><dd>{{ number_format($price / 100, 0, '', ' ') }} ₽</dd></div>@endforeach
                        </dl></section>
                    @endforeach
                </div>
                <p class="muted small">Указана полная стоимость выбранного срока одним платежом. Скидки на 3 месяца — от 5%, на 12 месяцев — от 10%. <a href="/pricing">Состав тарифов</a>.</p>
            </section>
            <section class="section service-card">
                <h2>Получение доступа</h2>
                <p>При первом входе после подтверждения документов предоставляется однократный пробный период на 72 часа. Покупка и приём реальных платежей пока закрыты. Порядок активации оплаченного доступа будет доступен до оформления заказа после подключения платёжной системы.</p>
                <p class="muted small">Услуга предоставляется в электронной форме; физическая доставка не требуется. Доступ используется через Telegram-аккаунт пользователя.</p>
                <p><a href="https://t.me/RUTenderFinder_bot">Открыть @RUTenderFinder_bot</a></p>
            </section>
            <section class="section">
                <h2>Документы</h2>
                <div class="document-cards">
                    @foreach ($documents as $href => $label)
                        <a class="document-card" href="{{ $href }}"><div><strong>{{ $label }}</strong><span>{{ match ($href) { '/terms' => 'Правила использования сервиса', '/offer' => 'Условия предоставления доступа', '/privacy' => 'Как обрабатываются персональные данные', default => 'Условия согласия на обработку данных' } }}</span></div><span aria-hidden="true">↗</span></a>
                    @endforeach
                </div>
            </section>
        </div>
        <aside class="service-card seller contacts" id="support" aria-labelledby="support-title">
            <h2 id="support-title">Контакты и поддержка</h2>
            <dl><div><dt>Оператор сервиса</dt><dd>{{ $company['short_name'] }}</dd></div><div><dt>ИНН</dt><dd>{{ $company['inn'] }}</dd></div><div><dt>ОГРНИП</dt><dd>{{ $company['ogrnip'] }}</dd></div><div><dt>Адрес</dt><dd>{{ $company['legal_address'] }}</dd></div></dl>
            <div>
                <a class="button button-secondary" href="mailto:{{ $company['support_email'] }}">Написать в поддержку</a>
                <p class="small"><a href="mailto:{{ $company['support_email'] }}">{{ $company['support_email'] }}</a></p>
                <p class="small muted">В боте: /support — общие вопросы, /paysupport — платежи и возвраты. В приложении: Профиль → Мои обращения.</p>
                <p class="small"><a href="/requisites">Полные банковские реквизиты</a>. Условия возврата изложены в <a href="/offer">публичной оферте</a>.</p>
            </div>
        </aside>
    </div>
@endif
@endsection
