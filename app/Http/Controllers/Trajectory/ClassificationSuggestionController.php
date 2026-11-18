<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\ClassificationSuggestionService;
use Illuminate\Http\Request;

class ClassificationSuggestionController extends Controller
{
    public function preview(Request $request, Space $space, ClassificationSuggestionService $service)
    {
        return response()->json($service->preview($request->user(), $space, $request->all()))
            ->header('Cache-Control', 'private, no-store');
    }
}
