<?php

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No `@mixin`, so the model behind it has to come from the naming convention
 * (`CountryResource` → `Country`) checked against the exported node ids — the
 * path that downgrades confidence.
 */
class CountryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso_code' => $this->iso_code,
        ];
    }
}
