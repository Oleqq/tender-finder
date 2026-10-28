<?php

namespace App\Services;

use App\Models\ProposalBlock;
use App\Models\ProposalDraft;
use App\Models\Team;
use App\Models\Tender;
use App\Models\User;

final class ProposalService
{
    public const TEMPLATE = "Коммерческое предложение\n\n{{компания}}\nЗакупка: {{название_закупки}}\nЗаказчик: {{заказчик}}\nИсточник: {{ссылка}}\n\n{{блоки}}\n\nЦена предложения: {{цена_предложения}}\nСрок исполнения: {{срок_исполнения}}\nКонтакты: {{контакты}}";

    public function enabled(User $user): bool
    {
        return app(AccessService::class)->hasActiveAccess($user)
            && in_array(app(TenderAnalyticsService::class)->tierFor($user), ['pro', 'ultra'], true);
    }

    /** @return list<array<string, mixed>> */
    public function blocks(User $user): array
    {
        return ProposalBlock::query()->where('user_id', $user->id)->orderBy('id')->get()->toArray();
    }

    public function current(User $user, Tender $tender, ?Team $team): ?ProposalDraft
    {
        return ProposalDraft::query()->where('user_id', $user->id)->where('tender_id', $tender->id)
            ->where('scope_key', $this->scope($team))->first();
    }

    public function scope(?Team $team): string
    {
        return $team === null ? 'personal' : 'team:'.$team->id;
    }

    public function fingerprint(Tender $tender): string
    {
        return hash('sha256', json_encode([$tender->title, TenderFacts::customer($tender), $tender->canonical_url,
            $tender->deadline_at?->toAtomString(), $tender->budget_amount, $tender->currency], JSON_THROW_ON_ERROR));
    }

    /** @param list<array{id: int, version: int}> $selected
     * @param  array<string, string|null>  $fields
     * @return array{body: string, snapshot: array<string, mixed>}
     */
    public function compose(User $user, Tender $tender, array $selected, array $fields, ?int $templateId, ?int $templateVersion): array
    {
        $bodies = [];
        $snapshotBlocks = [];
        foreach ($selected as $item) {
            $block = ProposalBlock::query()->where('user_id', $user->id)->findOrFail($item['id']);
            abort_unless($block->version === $item['version'] && $block->category !== 'template', 409, 'Выбранный блок изменён. Обновите библиотеку.');
            $bodies[] = $block->title."\n".$block->body;
            $snapshotBlocks[] = ['id' => $block->id, 'version' => $block->version, 'title' => $block->title, 'body' => $block->body];
        }
        $template = self::TEMPLATE;
        $savedTemplate = null;
        if ($templateId !== null) {
            $savedTemplate = ProposalBlock::query()->where('user_id', $user->id)->where('category', 'template')->findOrFail($templateId);
            abort_unless($savedTemplate->version === $templateVersion, 409, 'Шаблон изменён. Обновите библиотеку.');
            $template = $savedTemplate->body;
        }
        $price = trim($fields['price'] ?? '');
        if ($price !== '') {
            $parts = explode('.', str_replace(',', '.', $price));
            $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            $price = number_format($minor / 100, 2, ',', ' ').' ₽';
        }
        $values = [
            'название_закупки' => $tender->title, 'заказчик' => TenderFacts::customer($tender),
            'ссылка' => $tender->canonical_url,
            'срок_подачи' => $tender->deadline_at?->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i').' МСК',
            'компания' => $fields['company'] ?? null, 'контакты' => $fields['contacts'] ?? null,
            'цена_предложения' => $price, 'срок_исполнения' => $fields['delivery'] ?? null,
            'блоки' => implode("\n\n", $bodies),
        ];
        if ($tender->deadline_at === null) {
            $values['срок_подачи'] = null;
        }
        $combined = str_replace('{{блоки}}', $values['блоки'] !== '' ? $values['блоки'] : '{{блоки}}', $template);
        // One pass: text supplied by a user cannot expand recursively into another value.
        $body = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? null;

            return is_string($value) && trim($value) !== '' ? $value : $match[0];
        }, $combined) ?? $combined;
        abort_if(mb_strlen($body) > 50000, 422, 'Слишком большой черновик. Выберите меньше блоков.');

        return ['body' => $body, 'snapshot' => [
            'blocks' => $snapshotBlocks, 'fields' => $fields,
            'template' => $savedTemplate === null ? null : ['id' => $savedTemplate->id, 'version' => $savedTemplate->version],
            'tender_hash' => $this->fingerprint($tender),
            'source' => ['title' => $tender->title, 'customer' => TenderFacts::customer($tender), 'url' => $tender->canonical_url],
            'confirmed_at' => now()->toAtomString(),
        ]];
    }

    /** @return list<string> */
    public function missing(string $body): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string, mixed>|null */
    public function present(?ProposalDraft $draft, Tender $tender): ?array
    {
        if ($draft === null) {
            return null;
        }
        $snapshot = $draft->snapshot;
        $staleBlocks = [];
        foreach ($snapshot['blocks'] ?? [] as $block) {
            $current = ProposalBlock::query()->find($block['id']);
            if ($current === null || $current->version !== $block['version']) {
                $staleBlocks[] = $block['title'];
            }
        }
        $template = $snapshot['template'] ?? null;
        if ($template !== null) {
            $current = ProposalBlock::query()->find($template['id']);
            if ($current === null || $current->version !== $template['version']) {
                $staleBlocks[] = 'Шаблон';
            }
        }

        return ['id' => $draft->id, 'body' => $draft->body, 'version' => $draft->version,
            'missing' => $this->missing($draft->body), 'stale_blocks' => $staleBlocks,
            'tender_changed' => ($snapshot['tender_hash'] ?? '') !== $this->fingerprint($tender),
            'fields' => $snapshot['fields'] ?? [], 'block_ids' => array_column($snapshot['blocks'] ?? [], 'id'),
            'template_id' => $template['id'] ?? null];
    }
}
