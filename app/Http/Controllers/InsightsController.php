<?php

namespace App\Http\Controllers;

use App\Support\Insights;

class InsightsController extends Controller
{
    public function index()
    {
        return view('insights.index', ['data' => Insights::get(), 'map' => Insights::MAP]);
    }
}
