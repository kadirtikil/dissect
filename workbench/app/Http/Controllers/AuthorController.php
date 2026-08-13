<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workbench\App\Models\Author;

/**
 * The web half: no FormRequest and no resource, so the request shape has to
 * come from the inline `validate()` call and the response is a view or a
 * redirect — both of which have no body to describe.
 */
class AuthorController extends Controller
{
    public function index(): View
    {
        return view('workbench::authors', ['authors' => Author::all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:authors,email'],
            'country_id' => 'nullable|integer|exists:countries,id',
            'rating' => 'integer|min:0|max:5',
        ]);

        Author::create($validated);

        return redirect()->route('authors.index');
    }
}
