<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Plans');
    }
}
