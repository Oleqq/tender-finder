<?php

namespace App\Console\Commands;

use App\Services\PricingPageRenderer;
use Illuminate\Console\Command;

final class RenderPricingPages extends Command
{
    protected $signature = 'pricing:render';

    protected $description = 'Generate standalone pricing HTML from the server catalog';

    public function handle(PricingPageRenderer $renderer): int
    {
        foreach (['light' => '', 'dark' => '-dark'] as $theme => $suffix) {
            file_put_contents(base_path('docs/subscription-offer-preview'.$suffix.'.html'), $renderer->render($theme));
        }

        return self::SUCCESS;
    }
}
