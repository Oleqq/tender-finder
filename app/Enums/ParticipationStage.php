<?php

namespace App\Enums;

enum ParticipationStage: string
{
    case Studying = 'studying';
    case Preparing = 'preparing';
    case Submitted = 'submitted';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Studying => 'Изучаем',
            self::Preparing => 'Готовим заявку',
            self::Submitted => 'Подали',
            self::Won => 'Выиграли',
            self::Lost => 'Проиграли',
        };
    }
}
