<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Routes\RuleNormalizer;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\App\Models\Post;

/**
 * The patterns the route analysers used to answer wrongly, and with
 * confidence: each of these came out `certain` or `required` where the
 * honest answer was something else. The fixtures they read are at the bottom
 * of this file.
 */
class RouteAnalysisTest extends TestCase
{
    #[Test]
    public function a_controller_that_cannot_be_built_keeps_its_route(): void
    {
        Route::get('legacy', [LegacyThrowingController::class, 'index'])->middleware('throttle:60,1');

        $route = $this->routes()['GET:legacy'];

        // Only the controller's own share of the middleware is lost.
        $this->assertSame(['throttle:60,1'], $route['middleware']);
        $this->assertSame('LegacyThrowingController@index', $route['action']['label']);
    }

    #[Test]
    public function sometimes_required_is_not_required(): void
    {
        $normalizer = new RuleNormalizer;

        $this->assertFalse($normalizer->field('email', 'sometimes|required|email')['required']);
        $this->assertTrue($normalizer->field('email', 'required|email')['required']);
    }

    #[Test]
    public function an_upload_is_a_file_however_it_is_validated(): void
    {
        $normalizer = new RuleNormalizer;

        $this->assertSame('file', $normalizer->field('avatar', 'required|mimes:jpg,png|max:2048')['type']);
        $this->assertSame('file', $normalizer->field('doc', ['mimetypes:application/pdf'])['type']);
        $this->assertSame('file', $normalizer->field('doc', ['extensions:pdf'])['type']);
        $this->assertSame('file', $normalizer->field('doc', [\Illuminate\Validation\Rules\File::types(['pdf'])])['type']);
        $this->assertSame('image', $normalizer->field('photo', [\Illuminate\Validation\Rules\File::image()])['type']);
        $this->assertSame('array', $normalizer->field('ids', 'list')['type']);
    }

    #[Test]
    public function a_collection_without_a_return_type_is_a_collection(): void
    {
        Route::get('untyped-posts', [AnalysisController::class, 'untypedCollection']);

        $response = $this->routes()['GET:untyped-posts']['response'];

        $this->assertSame('resource-collection', $response['source']);
        $this->assertSame('Workbench\App\Http\Resources\PostResource', $response['class']);
    }

    #[Test]
    public function validation_whose_rules_are_built_elsewhere_is_unread_not_absent(): void
    {
        Route::post('built/request', [AnalysisController::class, 'requestValidatesBuiltRules']);
        Route::post('built/facade', [AnalysisController::class, 'facadeWithBuiltRules']);
        Route::post('built/helper', [AnalysisController::class, 'helperWithBuiltRules']);

        foreach (['POST:built/request', 'POST:built/facade', 'POST:built/helper'] as $id) {
            $request = $this->routes()[$id]['request'];

            $this->assertSame('inline-validate', $request['source'], $id);
            $this->assertSame('unknown', $request['confidence'], $id);
            $this->assertSame([], $request['fields'], $id);
        }
    }

    #[Test]
    public function the_rules_are_read_from_where_each_spelling_puts_them(): void
    {
        Route::post('validate/messages', [AnalysisController::class, 'validateWithMessages']);
        Route::post('validate/helper', [AnalysisController::class, 'validatorHelper']);
        Route::post('validate/bag', [AnalysisController::class, 'validateWithBag']);

        $routes = $this->routes();

        // The messages are the second argument, not the rules.
        $this->assertSame(['title'], array_column($routes['POST:validate/messages']['request']['fields'], 'path'));
        $this->assertSame(['body'], array_column($routes['POST:validate/helper']['request']['fields'], 'path'));
        $this->assertSame(['name'], array_column($routes['POST:validate/bag']['request']['fields'], 'path'));
    }

    #[Test]
    public function a_resources_make_is_not_a_validator(): void
    {
        Route::get('made', [AnalysisController::class, 'makesResource']);

        $request = $this->routes()['GET:made']['request'];

        $this->assertSame('none', $request['source']);
        $this->assertSame('certain', $request['confidence']);
    }

    #[Test]
    public function fluent_rules_read_from_source_keep_their_arguments(): void
    {
        Route::post('fluent', [AnalysisController::class, 'fluentRules']);

        $fields = array_column($this->routes()['POST:fluent']['request']['fields'], null, 'path');

        $this->assertContains('in:1,2,3', $fields['rating']['rules']);
        $this->assertContains('between:1,50', $fields['count']['rules']);
        // As `Rule::enum()` renders when it is run.
        $this->assertContains('in:"draft","live"', $fields['state']['rules']);
        // Another class's method is not a rule of the same name.
        $this->assertContains('Password', $fields['password']['rules']);
        $this->assertNotContains('min:8', $fields['password']['rules']);
    }

    #[Test]
    public function every_conditional_helper_leaves_its_key_optional(): void
    {
        Route::get('conditional', [AnalysisController::class, 'conditional']);

        $fields = array_column($this->routes()['GET:conditional']['response']['fields'], null, 'path');

        $this->assertFalse($fields['id']['conditional']);

        foreach (['posts_count', 'avg_rating', 'has_comments', 'role', 'deleted_at', 'draft'] as $key) {
            $this->assertTrue($fields[$key]['conditional'], $key);
        }
    }

    #[Test]
    public function a_status_is_only_read_off_a_response(): void
    {
        Route::get('paged', [AnalysisController::class, 'pagedJson']);
        Route::post('created', [AnalysisController::class, 'createdJson']);

        $routes = $this->routes();

        // `$request->json('limit', 50)` is a default value.
        $this->assertNull($routes['GET:paged']['response']['status']);
        $this->assertSame(201, $routes['POST:created']['response']['status']);
    }

    #[Test]
    public function an_arrow_function_route_is_read_like_a_closure(): void
    {
        Route::post('arrow', fn (Request $request) => $request->validate(['name' => 'required|string']));

        $request = $this->routes()['POST:arrow']['request'];

        $this->assertSame('inline-validate', $request['source']);
        $this->assertSame(['name'], array_column($request['fields'], 'path'));
    }

    #[Test]
    public function a_view_route_renders_a_view(): void
    {
        Route::view('about', 'workbench::authors');

        $response = $this->routes()['GET:about']['response'];

        $this->assertSame('view', $response['source']);
        $this->assertSame('certain', $response['confidence']);
    }

    /** @return array<string, array<string, mixed>> */
    protected function routes(): array
    {
        $export = $this->app->make(RouteExporter::class)->export();

        return array_column($export['routes'], null, 'id');
    }
}

/** The older kind, which registers its middleware in its constructor. */
class LegacyThrowingController extends BaseController
{
    public function __construct()
    {
        throw new RuntimeException('Needs a service this test does not bind.');
    }

    public function index(): array
    {
        return [];
    }
}

enum AnalysisState: string
{
    case Draft = 'draft';
    case Live = 'live';
}

class AnalysisController extends BaseController
{
    public function untypedCollection()
    {
        return \Workbench\App\Http\Resources\PostResource::collection(Post::all());
    }

    public function requestValidatesBuiltRules(Request $request)
    {
        $request->validate($this->rules());
    }

    public function facadeWithBuiltRules(Request $request)
    {
        Validator::make($request->all(), $this->rules())->validate();
    }

    public function helperWithBuiltRules(Request $request)
    {
        validator($request->all(), $this->rules())->validate();
    }

    public function validateWithMessages(Request $request)
    {
        $request->validate(['title' => 'required|string'], ['title.required' => 'Need a title']);
    }

    public function validatorHelper(Request $request)
    {
        validator($request->all(), ['body' => 'required'])->validate();
    }

    public function validateWithBag(Request $request)
    {
        $request->validateWithBag('profile', ['name' => 'required']);
    }

    public function makesResource()
    {
        return JsonResource::make(['ok' => true]);
    }

    public function fluentRules(Request $request)
    {
        $request->validate([
            'rating' => ['required', Rule::in([1, 2, 3])],
            'count' => [Rule::between(1, 50)],
            'state' => [Rule::enum(AnalysisState::class)],
            'password' => ['required', Password::min(8)->letters()],
        ]);
    }

    public function conditional(): ConditionalResource
    {
        return new ConditionalResource(new Post);
    }

    public function pagedJson(Request $request): JsonResponse
    {
        $limit = $request->json('limit', 50);

        return response()->json(['limit' => $limit]);
    }

    public function createdJson(): JsonResponse
    {
        return response()->json(['id' => 1], 201);
    }

    protected function rules(): array
    {
        return ['title' => 'required'];
    }
}

class ConditionalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'posts_count' => $this->whenCounted('posts'),
            'avg_rating' => $this->whenAggregated('ratings', 'stars', 'avg'),
            'has_comments' => $this->whenExistsLoaded('comments'),
            'role' => $this->whenPivotLoaded('memberships', fn () => $this->pivot->role),
            'deleted_at' => $this->whenNull($this->deleted_at),
            'draft' => $this->unless($this->published, true),
        ];
    }
}
