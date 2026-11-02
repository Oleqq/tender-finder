<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Отмена автопродления — TenderFinder</title>
<style>body{margin:0;padding:24px;font:16px/1.65 system-ui;background:#f5f7fc;color:#182440;overflow-wrap:anywhere}main{max-width:600px;margin:32px auto;padding:24px;border:1px solid #aab4cc;border-radius:20px}h1{font-size:28px;line-height:1.3}button{width:100%;font:inherit;font-weight:700;background:#354edc;color:white;border:0;border-radius:12px;padding:16px;cursor:pointer}a{color:#4359d9} @media(prefers-color-scheme:dark){body{background:#101318;color:#edf0f7}a{color:#a9b5ff}}</style></head><body><main>
<h1>{{ $renewal['state'] === 'cancelled' ? 'Автопродление отключено' : 'Отмена автопродления' }}</h1>
@if($renewal['provider'] === 'demo')<p><strong>Тестовая подписка. Реальные деньги не списываются.</strong></p>@endif
<p>{{ strtoupper($renewal['plan_code']) }} · {{ number_format($renewal['amount_minor']/100, 0, '', ' ') }} ₽ каждые {{ $renewal['duration_months'] }} мес.</p>
@if($renewal['state'] !== 'cancelled')<p>Следующая запланированная дата: {{ \Illuminate\Support\Carbon::parse($renewal['next_charge_at'])->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК.</p>
<p>Нажмите кнопку, чтобы отключить будущие продления. Оплаченный доступ сохранится до конца срока.</p>
<form method="POST" action="{{ $renewal['cancel_url'] }}">@csrf<button type="submit">Отменить автопродление</button></form>
@else<p>Будущие продления отменены. Оплаченный доступ сохраняется до конца срока.</p>@endif
<p><a href="/info#support">Связаться с поддержкой</a></p>
</main></body></html>
