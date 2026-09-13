<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\Tender;
use App\Models\TenderChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** @phpstan-type CalendarEvent array{id: string, tender_id: int, title: string, tender_title: string, kind: string, date: string, starts_at: string, all_day: bool, url: string} */
final class TenderCalendarService
{
    public function timezone(User $user): string
    {
        $timezone = NotificationPreference::query()->where('user_id', $user->id)->value('timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Moscow';
    }

    /** @return list<CalendarEvent> */
    public function events(User $user, string $month): array
    {
        $timezone = $this->timezone($user);
        $start = Carbon::createFromFormat('!Y-m', $month, $timezone);
        $end = $start->copy()->addMonth();
        $first = $start->format('Y-m-d');
        $last = $end->copy()->subDay()->format('Y-m-d');
        $from = $start->copy()->utc();
        $until = $end->copy()->utc();
        $due = fn ($q) => $q->whereNull('completed_at')->whereBetween('due_on', [$first, $last]);
        $tenders = app(TenderWorkService::class)->accessible($user)
            ->whereDoesntHave('userStates', fn (Builder $q) => $q->where('user_id', $user->id)->whereIn('status', ['dismissed', 'archived']))
            ->where(function (Builder $q) use ($user, $first, $last, $from, $until, $due): void {
                $q->where(fn (Builder $d) => $d->where('deadline_at', '>=', $from)->where('deadline_at', '<', $until))
                    ->orWhereHas('userStates', fn (Builder $s) => $s->where('user_id', $user->id)->whereBetween('next_action_on', [$first, $last]))
                    ->orWhereHas('participations', fn (Builder $p) => $p->where('user_id', $user->id)->whereHas('items', $due));
            })
            ->with(['userStates' => fn ($q) => $q->where('user_id', $user->id),
                'participations' => fn ($q) => $q->where('user_id', $user->id)->with(['items' => $due])])
            ->limit(5001)->get();
        abort_if($tenders->count() > 5000, 422, 'Слишком много закупок для одного месяца.');
        $events = [];
        foreach ($tenders as $tender) {
            if ($tender->deadline_at !== null && $tender->deadline_at->gte($from) && $tender->deadline_at->lt($until)) {
                $date = $tender->deadline_at->copy()->setTimezone($timezone);
                $events[] = $this->event($user, $tender, 'deadline', $tender->id, 'Подача заявки', $date->toAtomString(), $date->format('Y-m-d'), false);
            }
            $action = $tender->userStates->first()?->next_action_on?->format('Y-m-d');
            if ($action !== null && $action >= $first && $action <= $last) {
                $events[] = $this->event($user, $tender, 'action', $tender->id, 'Личное действие', $action, $action, true);
            }
            foreach ($tender->participations as $participation) {
                foreach ($participation->items as $item) {
                    /** @var TenderChecklistItem $item */
                    $date = $item->due_on?->format('Y-m-d');
                    if ($date !== null) {
                        $events[] = $this->event($user, $tender, 'task', $item->id, $item->title, $date, $date, true);
                    }
                }
            }
        }
        abort_if(count($events) > 10000, 422, 'Слишком много событий для экспорта месяца.');
        usort($events, fn (array $a, array $b): int => [$a['date'], $a['starts_at'], $a['id']] <=> [$b['date'], $b['starts_at'], $b['id']]);

        return $events;
    }

    /** @return CalendarEvent */
    private function event(User $user, Tender $tender, string $kind, int $id, string $title, string $startsAt, string $date, bool $allDay): array
    {
        return ['id' => hash('sha256', $user->id.':'.$kind.':'.$id).'@tenderfinder',
            'tender_id' => $tender->id, 'title' => $title, 'tender_title' => $tender->title,
            'kind' => $kind, 'date' => $date, 'starts_at' => $startsAt, 'all_day' => $allDay,
            'url' => route('tenders.work', $tender)];
    }

    /** @param list<CalendarEvent> $events */
    public function ics(array $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TenderFinder//Tender Calendar//RU', 'CALSCALE:GREGORIAN'];
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event['id'];
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            if ($event['all_day']) {
                $date = Carbon::createFromFormat('!Y-m-d', $event['date']);
                $lines[] = 'DTSTART;VALUE=DATE:'.$date->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$date->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.Carbon::parse($event['starts_at'])->utc()->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.$this->escape($event['title'].' — '.$event['tender_title']);
            $lines[] = 'DESCRIPTION:'.$this->escape('Проверьте актуальные условия закупки перед подачей заявки.');
            $lines[] = 'URL:'.str_replace(["\r", "\n"], '', $event['url']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        // RFC 5545: CRLF, fold at 75 octets without splitting UTF-8 characters.
        return implode("\r\n", array_map(function (string $line): string {
            $parts = [];
            while (strlen($line) > ($parts === [] ? 75 : 74)) {
                $part = mb_strcut($line, 0, $parts === [] ? 75 : 74, 'UTF-8');
                $parts[] = $part;
                $line = substr($line, strlen($part));
            }
            $parts[] = $line;

            return implode("\r\n ", $parts);
        }, $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', "\r\n", "\r", "\n", ';', ','], ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'], $text);
    }
}
