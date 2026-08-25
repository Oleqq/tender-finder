<?php

use App\Jobs\MatchTender;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Services\TenderMatchingService;
use Illuminate\Support\Facades\Queue;

it('runs the local synthetic RSS path from first-poll silence to explainable tender match', function () {
    Queue::fake();

    $this->artisan('tenders:seed-local-scenario')->assertSuccessful();
    $this->artisan('tenders:import-fixture', ['fixture' => 'initial'])->assertSuccessful();

    expect(Tender::query()->count())->toBe(1)
        ->and(TenderQueryMatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $this->artisan('tenders:import-fixture', ['fixture' => 'next'])->assertSuccessful();
    Queue::assertPushed(MatchTender::class, 1);

    $newTender = Tender::query()->where('reg_number', '11234567890123456789')->firstOrFail();
    $matches = app(TenderMatchingService::class)->matchTender($newTender);

    expect($matches)->toBe(1)
        ->and(TenderQueryMatch::query()->count())->toBe(1)
        ->and(SearchQuery::query()->where('name', 'Локальная проверка: поддержка сайтов')->exists())->toBeTrue();

    $this->artisan('tenders:show-local-matches')->assertSuccessful();
});
