<?php

namespace App\Services;

use App\Models\PriceCatalog;
use App\Models\PriceCatalogItem;
use App\Models\User;
use App\Models\WinnerOpportunity;
use App\Services\PriceCatalog\CatalogMatcher;
use App\Services\PriceCatalog\CatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WinnerOpportunityService
{
    public function enabled(User $user): bool
    {
        return app(ParticipationExecutionService::class)->enabled($user);
    }

    /** @return Builder<WinnerOpportunity> */
    public function owned(User $user): Builder
    {
        return WinnerOpportunity::query()->where('user_id', $user->id);
    }

    /** @param array<string, string> $evidence */
    public function fingerprint(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    public function stale(WinnerOpportunity $row, User $user): bool
    {
        $source = $row->draft_source;

        return $source === null || ($source['evidence_hash'] ?? '') !== $this->fingerprint($row->evidence)
            || ($source['catalog_version'] ?? -1) !== app(CatalogService::class)->present($user)['version'];
    }

    /** @return array<string, mixed> */
    public function present(WinnerOpportunity $row, User $user): array
    {
        return ['id' => $row->id, 'version' => $row->version, 'evidence' => $row->evidence,
            'body' => $row->body, 'draft_source' => $row->draft_source, 'stale' => $this->stale($row, $user),
            'outcome' => $row->outcome, 'outcome_note' => $row->outcome_note, 'archived' => $row->archived_at !== null];
    }

    /** @return list<array<string, mixed>> */
    public function candidates(WinnerOpportunity $row, User $user): array
    {
        $catalog = app(CatalogService::class)->present($user);

        return app(CatalogMatcher::class)->candidates(['name' => $row->evidence['scope']], $catalog['items']);
    }

    /** @param list<int> $ids
     * @param  array<string, mixed>  $data
     */
    public function compose(WinnerOpportunity $row, User $user, array $ids, array $data): void
    {
        $catalog = app(CatalogService::class)->present($user);
        abort_unless($catalog['version'] === (int) $data['catalog_version'], 409, 'Прайс изменился. Обновите страницу и проверьте позиции.');
        $selected = PriceCatalogItem::query()->whereIn('price_catalog_id', PriceCatalog::query()->where('user_id', $user->id)->select('id'))
            ->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'characteristics', 'price', 'quantity', 'unit', 'region']);
        abort_unless($selected->count() === count($ids), 422, 'Выберите только позиции своего прайса.');
        $items = $selected->toArray();
        $e = $row->evidence;
        $lines = array_map(fn (array $item): string => '• '.$item['name'].(filled($item['characteristics']) ? ' — '.$item['characteristics'] : ''), $items);
        $body = "Здравствуйте, {$e['winner_name']}!\n\n{$data['company']} предлагает обсудить поставку или выполнение части работ по закупке «{$e['title']}».\n"
            ."Источник итогов: {$e['source_url']}\n{$e['source_reference']}; дата итогов: {$e['result_on']}.\n\n"
            ."Можем обсудить следующие позиции:\n".implode("\n", $lines)."\n\nОснование предложения: {$data['rationale']}\n\n"
            ."Просим сообщить, актуально ли для вас привлечение поставщика или подрядчика по этим позициям. Объём, соответствие требованиям, наличие, цену и сроки предлагаем согласовать отдельно.\n\n{$data['contacts']}";
        abort_if(mb_strlen($body) > 20000, 422, 'Черновик превышает 20 000 символов. Уменьшите число позиций или длину описаний.');
        $row->body = $body;
        $row->draft_source = ['evidence_hash' => $this->fingerprint($e), 'catalog_version' => $catalog['version'],
            'items' => $items, 'rationale' => $data['rationale'], 'company' => $data['company'], 'contacts' => $data['contacts'],
            'composed_at' => now()->toAtomString()];
    }

    /** @param array<string, mixed> $snapshot */
    public function event(WinnerOpportunity $row, string $action, array $snapshot): void
    {
        DB::table('winner_opportunity_events')->insert(['opportunity_id' => $row->id, 'version' => $row->version,
            'action' => $action, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
