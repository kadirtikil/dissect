{{-- Exists so AuthorController::index() is a route you can actually hit, not
     just one the exporter reads. --}}
<ul>
    @foreach ($authors as $author)
        <li>{{ $author->name }}</li>
    @endforeach
</ul>
