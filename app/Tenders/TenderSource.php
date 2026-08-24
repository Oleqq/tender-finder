<?php

namespace App\Tenders;

use App\Models\SourceFeed;

interface TenderSource
{
    public function fetch(SourceFeed $feed): SourceFetchResult;
}
