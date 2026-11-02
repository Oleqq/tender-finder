@extends('layouts.public')
@section('title', 'Отмена автопродления')
@section('content')
<div class="cancel-shell">
    <article class="paper">
        <span class="eyebrow">Управление подпиской</span>
        <h1>{{ $renewal['state'] === 'cancelled' ? 'Автопродление отключено' : 'Отмена автопродления' }}</h1>
        @if($renewal['provider'] === 'demo')<p class="status-note"><strong>Тестовая подписка.</strong> Реальные деньги не списываются.</p>@endif
        <p><strong>{{ strtoupper($renewal['plan_code']) }}</strong> · {{ number_format($renewal['amount_minor']/100, 0, '', ' ') }} ₽ каждые {{ $renewal['duration_months'] }} мес.</p>
        @if($renewal['state'] !== 'cancelled')
            <p>Следующая запланированная дата: {{ \Illuminate\Support\Carbon::parse($renewal['next_charge_at'])->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК.</p>
            <p>Нажмите кнопку, чтобы отключить будущие продления. Оплаченный доступ сохранится до конца срока.</p>
            <form method="POST" action="{{ $renewal['cancel_url'] }}">@csrf<button class="button" type="submit">Отменить автопродление</button></form>
        @else
            <p class="status-note status-success" role="status">Будущие продления отменены. Оплаченный доступ сохраняется до конца срока.</p>
        @endif
        <p class="small"><a href="/info#support">Связаться с поддержкой</a></p>
    </article>
</div>
@endsection
