<?php

namespace App\Console\Commands;

use App\Models\TenderQueryMatch;
use Illuminate\Console\Command;

class ShowLocalTenderMatches extends Command
{
    protected $signature = 'tenders:show-local-matches';

    protected $description = 'Show synthetic local tender cards and their matching reasons.';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('Synthetic local matches are restricted to local and test environments.');

            return self::FAILURE;
        }

        $matches = TenderQueryMatch::query()
            ->whereHas('searchQuery', fn ($query) => $query->where('name', 'Локальная проверка: поддержка сайтов'))
            ->whereHas('tender', fn ($query) => $query->where('source', 'eis_rss'))
            ->with(['searchQuery:id,name', 'tender'])
            ->latest('matched_at')
            ->get();

        if ($matches->isEmpty()) {
            $this->components->warn('No local matches yet. Run the initial fixture, then the next fixture while the queue worker is running.');

            return self::SUCCESS;
        }

        $this->table(
            ['Мониторинг', 'Тендер', 'Реестр', 'Почему попал в карточку'],
            $matches->map(fn (TenderQueryMatch $match): array => [
                $match->searchQuery->name,
                $match->tender->title,
                $match->tender->reg_number ?? 'не указан',
                $this->reasonSummary($match->match_reasons ?? []),
            ])->all(),
        );
        $this->line('All records above are synthetic local fixtures, not live EIS data.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $reasons */
    private function reasonSummary(array $reasons): string
    {
        $parts = [];

        if (($reasons['keywords'] ?? []) !== []) {
            $parts[] = 'ключевые слова';
        }

        foreach (['region' => 'регион', 'budget' => 'сумма', 'deadline' => 'срок'] as $key => $label) {
            if (($reasons[$key] ?? null) === 'matched') {
                $parts[] = $label;
            }
        }

        return $parts === [] ? 'настройки мониторинга' : implode(', ', $parts);
    }
}
