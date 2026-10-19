<?php

namespace App\Services;

final class MiniAppDestination
{
    /** @param array<string, mixed> $destination
     *  @return array{screen: string, tender_id?: int, team_id?: int}
     */
    public static function normalize(array $destination): array
    {
        $screen = $destination['screen'] ?? 'tenders';
        if (! in_array($screen, ['tenders', 'digest', 'tender', 'work', 'plans', 'profile', 'support'], true)) {
            return ['screen' => 'tenders'];
        }

        $result = ['screen' => $screen];
        if (in_array($screen, ['tender', 'work'], true)) {
            $id = filter_var($destination['tender_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                return ['screen' => 'tenders'];
            }
            $result['tender_id'] = (int) $id;
        }

        if (in_array($screen, ['tenders', 'work'], true)) {
            $teamId = filter_var($destination['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($teamId !== false) {
                $result['team_id'] = (int) $teamId;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $destination */
    public static function path(array $destination): string
    {
        $target = self::normalize($destination);

        return match ($target['screen']) {
            'tender' => route('tenders.show', ['tender' => $target['tender_id'] ?? 0], false),
            'work' => route('tenders.work', ['tender' => $target['tender_id'] ?? 0,
                ...(isset($target['team_id']) ? ['team_id' => $target['team_id']] : [])], false),
            'plans' => route('plans', [], false),
            'profile' => route('profile', [], false),
            'support' => route('support.index', [], false),
            'digest' => route('tenders', ['query_id' => 0], false),
            default => route('tenders', isset($target['team_id']) ? ['team_id' => $target['team_id']] : [], false),
        };
    }
}
