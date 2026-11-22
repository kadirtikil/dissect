<?php

namespace KdrDev\Dissect\Tests\OpenApi;

use KdrDev\Dissect\OpenApi\Schemas;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemasTest extends TestCase
{
    #[Test]
    public function a_type_rule_becomes_a_type_and_the_rules_are_kept_as_written(): void
    {
        $this->assertSame(
            ['type' => 'integer', 'description' => '`required|integer|exists:authors,id`'],
            Schemas::fromRules('integer', ['required', 'integer', 'exists:authors,id']),
        );
        $this->assertSame(['type' => 'number', 'description' => '`numeric`'], Schemas::fromRules('numeric', ['numeric']));
        $this->assertSame(['type' => 'string', 'format' => 'email', 'description' => '`email`'], Schemas::fromRules('email', ['email']));
        $this->assertSame(['type' => 'string', 'format' => 'uri', 'description' => '`url`'], Schemas::fromRules('url', ['url']));
        $this->assertSame(['type' => 'string', 'format' => 'binary', 'description' => '`file`'], Schemas::fromRules('file', ['file']));
    }

    #[Test]
    public function no_type_rule_says_nothing_about_the_type(): void
    {
        $this->assertSame(['description' => '`required|confirmed`'], Schemas::fromRules(null, ['required', 'confirmed']));
        $this->assertSame([], Schemas::fromRules(null, []));
    }

    #[Test]
    public function bounds_follow_the_type_as_in_laravel(): void
    {
        $this->assertSame(['type' => 'string', 'maxLength' => 255, 'description' => '`string|max:255`'], Schemas::fromRules('string', ['string', 'max:255']));
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => '`integer|between:1,50`'], Schemas::fromRules('integer', ['integer', 'between:1,50']));
        $this->assertSame(1.5, Schemas::fromRules('numeric', ['numeric', 'min:1.5'])['minimum']);
        $this->assertSame(['minItems' => 2, 'maxItems' => 2], array_intersect_key(Schemas::fromRules('array', ['array', 'size:2']), ['minItems' => 0, 'maxItems' => 0]));

        // A file's size is in kilobytes, which no keyword means.
        $this->assertArrayNotHasKey('maxLength', Schemas::fromRules('file', ['file', 'max:2048']));
    }

    #[Test]
    public function in_becomes_an_enum_and_numbers_stay_numbers(): void
    {
        $this->assertSame(['public', 'unlisted', 'private'], Schemas::fromRules(null, ['in:"public","unlisted","private"'])['enum']);
        $this->assertSame([1, 2, 3], Schemas::fromRules('integer', ['integer', 'in:1,2,3'])['enum']);
        $this->assertSame(['1', '2'], Schemas::fromRules('string', ['string', 'in:1,2'])['enum']);
    }

    #[Test]
    public function an_enum_of_strings_is_a_string(): void
    {
        $this->assertSame('string', Schemas::fromRules(null, ['in:"public","unlisted"'])['type']);
        $this->assertArrayNotHasKey('type', Schemas::fromRules(null, ['required']));
    }

    #[Test]
    public function nullable_adds_null_to_the_type_and_the_enum(): void
    {
        $this->assertSame(['string', 'null'], Schemas::fromRules('string', ['nullable', 'string'])['type']);
        $this->assertSame(['a', 'b', null], Schemas::fromRules('string', ['nullable', 'string', 'in:a,b'])['enum']);
        $this->assertArrayNotHasKey('type', Schemas::fromRules(null, ['nullable']));
    }

    #[Test]
    public function a_regex_is_a_pattern_only_when_it_can_be_one(): void
    {
        $this->assertSame('^[a-z-]+$', Schemas::fromRules('string', ['regex:/^[a-z-]+$/'])['pattern']);
        $this->assertArrayNotHasKey('pattern', Schemas::fromRules('string', ['regex:/^[a-z]+$/i']));
        $this->assertSame('date', Schemas::fromRules('date', ['date_format:Y-m-d'])['format']);
    }

    #[Test]
    public function request_fields_fold_into_objects_and_lists(): void
    {
        $schema = Schemas::request([
            ['path' => 'title', 'type' => 'string', 'required' => true, 'rules' => ['required', 'string']],
            ['path' => 'meta', 'type' => 'array', 'required' => false, 'rules' => ['nullable', 'array']],
            ['path' => 'tags', 'type' => 'array', 'required' => false, 'rules' => ['array', 'max:10']],
            ['path' => 'tags[].name', 'type' => 'string', 'required' => true, 'rules' => ['required', 'string']],
            ['path' => 'ids[]', 'type' => 'integer', 'required' => false, 'rules' => ['integer']],
            ['path' => 'address.city', 'type' => 'string', 'required' => true, 'rules' => ['required', 'string']],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['title'], $schema['required']);

        // `array` alone could be either; with no children it stays either.
        $this->assertSame(['array', 'object', 'null'], $schema['properties']['meta']['type']);

        // Children say which: a list of objects, a list of integers, an object.
        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame(10, $schema['properties']['tags']['maxItems']);
        $this->assertSame(['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '`required|string`']], 'required' => ['name']], $schema['properties']['tags']['items']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'integer', 'description' => '`integer`']], $schema['properties']['ids']);
        $this->assertSame('object', $schema['properties']['address']['type']);
        $this->assertSame(['city'], $schema['properties']['address']['required']);
    }

    #[Test]
    public function response_fields_are_required_unless_conditional_and_untyped_when_unknown(): void
    {
        $schema = Schemas::response([
            ['path' => 'id', 'kind' => 'scalar', 'conditional' => false],
            ['path' => 'author', 'kind' => 'object', 'conditional' => true],
            ['path' => 'author.name', 'kind' => 'scalar', 'conditional' => false],
            ['path' => 'comments[]', 'kind' => 'array', 'conditional' => true],
            ['path' => 'comments[].body', 'kind' => 'scalar', 'conditional' => false],
        ]);

        $this->assertSame(['id'], $schema['required']);
        $this->assertSame([], $schema['properties']['id']);
        $this->assertSame('object', $schema['properties']['author']['type']);
        $this->assertStringContainsString('whenLoaded()', $schema['properties']['author']['description']);
        $this->assertSame(['name'], $schema['properties']['author']['required']);
        $this->assertSame('array', $schema['properties']['comments']['type']);
        $this->assertSame(['body'], $schema['properties']['comments']['items']['required']);
    }

    #[Test]
    public function a_parent_listed_after_its_children_gives_the_same_schema(): void
    {
        $fields = [
            ['path' => 'a.b', 'kind' => 'scalar'],
            ['path' => 'a', 'kind' => 'object'],
            ['path' => 'c', 'kind' => 'scalar'],
        ];

        $this->assertSame(
            json_encode(Schemas::response($fields)),
            json_encode(Schemas::response([$fields[1], $fields[0], $fields[2]])),
        );
    }

    #[Test]
    public function a_file_makes_the_body_a_form(): void
    {
        $this->assertTrue(Schemas::hasFile([['path' => 'avatar', 'type' => 'image']]));
        $this->assertFalse(Schemas::hasFile([['path' => 'name', 'type' => 'string']]));
    }
}
