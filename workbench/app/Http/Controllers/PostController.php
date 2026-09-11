<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Events\PostPublished;
use Workbench\App\Http\Requests\StorePostRequest;
use Workbench\App\Http\Requests\UpdatePostRequest;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Jobs\PublishPost;
use Workbench\App\Jobs\SyncAuthorProfile;
use Workbench\App\Models\Post;

/**
 * The conventional API controller — the shape the exporter is expected to read
 * without any help: a FormRequest in, a JsonResource out, and route model
 * binding on the members.
 */
class PostController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PostResource::collection(Post::with('author')->paginate());
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = Post::create($request->validated());

        // The plain case: a static dispatch inside a controller action, which
        // is what the route join is built to find.
        PublishPost::dispatch($post)->afterCommit();

        // The same job dispatched from two actions onto two different queues —
        // see SyncAuthorProfile.
        SyncAuthorProfile::dispatch($post->author)->onQueue('sync');

        PostPublished::dispatch($post);

        return PostResource::make($post)->response()->setStatusCode(201);
    }

    /** Implicit binding: `{post}` resolves to a Post, which is a model link. */
    public function show(Post $post): PostResource
    {
        return new PostResource($post->load('author', 'comments'));
    }

    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        $post->update($request->validated());

        return new PostResource($post);
    }

    public function destroy(Post $post): JsonResponse
    {
        $post->delete();

        return response()->json(['deleted' => true, 'id' => $post->id]);
    }
}
