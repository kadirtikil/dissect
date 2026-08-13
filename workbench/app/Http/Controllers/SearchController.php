<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Http\Resources\SearchHitResource;

/**
 * An invokable controller returning a resource with no model behind it — the
 * combination that has to degrade to "fields, but no link" rather than either
 * failing or guessing a model.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => 'required|string|min:2',
            'limit' => 'integer|between:1,50',
        ]);

        return SearchHitResource::collection(collect());
    }
}
