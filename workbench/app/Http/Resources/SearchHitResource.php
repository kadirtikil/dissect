<?php

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource with no model behind it at all — neither a `@mixin` nor a name
 * that matches anything in the scan.
 *
 * The analyzer should still report the field list and simply leave the model
 * link empty, rather than guessing at `SearchHit`.
 */
class SearchHitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'score' => $this->score,
        ];
    }
}
