<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;

final readonly class EisRssSearchCriteria
{
    public function __construct(
        public bool $law44 = false,
        public bool $law223 = false,
        public bool $stageApplication = false,
        public bool $stageCommission = false,
        public bool $stageCompleted = false,
        public bool $stageCancelled = false,
        public bool $jointPurchase = false,
        public bool $placedBySeparateSubdivision = false,
        public bool $unionStateBudget = false,
        public bool $createdByCustomerRepresentative = false,
        public bool $smpSono = false,
        public ?string $budgetFrom = null,
        public ?string $budgetTo = null,
        public ?string $publishedFrom = null,
        public ?string $publishedTo = null,
    ) {}

    /** @return array<string, string|int> */
    public function queryParameters(): array
    {
        $parameters = [];

        if ($this->law44) {
            $parameters['fz44'] = 'on';
        }

        if ($this->law223) {
            $parameters['fz223'] = 'on';
        }

        if ($this->stageApplication) {
            $parameters['af'] = 'on';
        }

        if ($this->stageCommission) {
            $parameters['ca'] = 'on';
        }

        if ($this->stageCompleted) {
            $parameters['pc'] = 'on';
        }

        if ($this->stageCancelled) {
            $parameters['pa'] = 'on';
        }

        if ($this->jointPurchase) {
            $parameters['jointPurchase'] = 'on';
        }

        if ($this->placedBySeparateSubdivision) {
            $parameters['isByPlacedSeparateSubdivisions'] = 'on';
        }

        if ($this->unionStateBudget) {
            $parameters['budgetUnionState'] = 'on';
        }

        if ($this->createdByCustomerRepresentative) {
            $parameters['isByRepresentativeCreated'] = 'on';
        }

        if ($this->smpSono) {
            $parameters['procurementSMPAndSONO'] = 'on';
        }

        if ($this->budgetFrom !== null) {
            $parameters['priceFromGeneral'] = $this->money($this->budgetFrom);
        }

        if ($this->budgetTo !== null) {
            $parameters['priceToGeneral'] = $this->money($this->budgetTo);
        }

        if ($this->budgetFrom !== null || $this->budgetTo !== null) {
            $parameters['currencyIdGeneral'] = 1;
        }

        if ($this->publishedFrom !== null) {
            $parameters['publishDateFrom'] = $this->date($this->publishedFrom);
        }

        if ($this->publishedTo !== null) {
            $parameters['publishDateTo'] = $this->date($this->publishedTo);
        }

        return $parameters;
    }

    private function date(string $value): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $value)->format('d.m.Y');
    }

    private function money(string $value): string
    {
        $normalized = number_format((float) str_replace(',', '.', $value), 2, '.', '');
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }
}
