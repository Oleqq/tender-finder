<?php

namespace App\Services;

final class PricingPageRenderer
{
    public function render(string $theme): string
    {
        abort_unless(in_array($theme, ['light', 'dark'], true), 404);
        $values = [];
        foreach (SubscriptionCatalog::OFFERS as $code => $plan) {
            foreach ($plan['prices'] as $months => $minor) {
                $rub = intdiv($minor, 100);
                $values['{{'.$code.'_'.$months.'_rub}}'] = (string) $rub;
                $values['{{'.$code.'_'.$months.'_formatted}}'] = number_format($rub, 0, '', "\u{00A0}")."\u{00A0}₽";
            }
        }

        return strtr((string) file_get_contents(resource_path('pricing/'.$theme.'.html')), $values);
    }
}
