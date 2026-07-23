<?php

/**
 * Generates a deliberately huge fixture schema for developing the viewer.
 *
 * The curated models in workbench/app/Models are small on purpose — they cover
 * every relation family and column kind, and the test suite asserts against
 * them. This produces the opposite fixture: a schema big enough that layout,
 * the minimap, saved views and rendering performance are exercised the way a
 * real application exercises them.
 *
 *   php workbench/scripts/generate-huge-models.php [count] [seed]
 *
 * Output (both gitignored — the generator is the thing worth committing):
 *
 *   workbench/app/Huge/*.php                       models
 *   workbench/database/migrations-huge/*.php       their tables
 *
 * WorkbenchServiceProvider points the viewer at workbench/app/Huge whenever
 * that directory exists, so generating is the whole switch. Delete it to go
 * back to the small fixture.
 *
 * Deterministic: the same count and seed produce byte-identical output, so a
 * regeneration does not reshuffle a graph you have already arranged.
 */

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Support\Str;

$count = max(1, (int) ($argv[1] ?? 120));
$seed = (int) ($argv[2] ?? 20260723);

mt_srand($seed);

$root = dirname(__DIR__, 2);
$modelDir = $root.'/workbench/app/Huge';
$migrationDir = $root.'/workbench/database/migrations-huge';

/*
|--------------------------------------------------------------------------
| Vocabulary
|--------------------------------------------------------------------------
|
| Names come from per-domain pools rather than Model1..Model120, because the
| point is to see what a real schema looks like: clustered names, uneven
| domain sizes, and the occasional model everything points at.
*/

$domains = [
    'Billing' => ['Invoice', 'Payment', 'Refund', 'Subscription', 'Plan', 'Coupon', 'Discount', 'CreditNote', 'TaxRate', 'BillingAccount', 'Charge', 'Dunning', 'PriceTier', 'Wallet'],
    'Crm' => ['Lead', 'Opportunity', 'Contact', 'Company', 'Pipeline', 'Stage', 'Activity', 'CallLog', 'MeetingNote', 'Territory', 'Quota', 'Forecast', 'Segment', 'Campaign'],
    'Catalog' => ['Product', 'Variant', 'Sku', 'Brand', 'Collection', 'Attribute', 'AttributeValue', 'PriceList', 'Bundle', 'Review', 'Specification', 'MediaAsset', 'Barcode', 'Taxonomy'],
    'Inventory' => ['Warehouse', 'StockItem', 'StockMovement', 'Bin', 'Lot', 'SerialNumber', 'Supplier', 'PurchaseOrder', 'PurchaseOrderLine', 'Receipt', 'CycleCount', 'Reservation', 'Replenishment', 'Shrinkage'],
    'Sales' => ['Order', 'OrderLine', 'Cart', 'CartItem', 'Fulfilment', 'Shipment', 'ShippingRate', 'Carrier', 'ReturnRequest', 'GiftCard', 'Channel', 'Storefront', 'Basket', 'Checkout'],
    'Support' => ['Ticket', 'TicketMessage', 'Macro', 'SlaPolicy', 'Escalation', 'Satisfaction', 'KnowledgeArticle', 'Faq', 'SupportQueue', 'Agent', 'Shift', 'Handover', 'Incident', 'Postmortem'],
    'Identity' => ['Account', 'Role', 'Permission', 'Team', 'Membership', 'ApiToken', 'Session', 'LoginAttempt', 'Invitation', 'Sso', 'Directory', 'ServiceAccount', 'Policy', 'Consent'],
    'Content' => ['Page', 'Block', 'Template', 'Menu', 'MenuItem', 'Redirect', 'Translation', 'Locale', 'Banner', 'Snippet', 'Form', 'FormField', 'Submission', 'Sitemap'],
    'Logistics' => ['Route', 'Stop', 'Vehicle', 'Driver', 'Trip', 'Manifest', 'Depot', 'Zone', 'Dispatch', 'ProofOfDelivery', 'Waybill', 'Leg', 'Hub', 'Slot'],
    'Finance' => ['Ledger', 'JournalEntry', 'CostCentre', 'Budget', 'Forecasting', 'Currency', 'ExchangeRate', 'Reconciliation', 'BankAccount', 'Statement', 'Expense', 'Approval', 'Asset', 'Depreciation'],
    'People' => ['Employee', 'Department', 'Position', 'Contract', 'Absence', 'TimeEntry', 'Payslip', 'Benefit', 'Onboarding', 'Appraisal', 'Skill', 'Certification', 'Candidate', 'Vacancy'],
    'Analytics' => ['Event', 'Metric', 'Dashboard', 'Widget', 'Report', 'Cohort', 'Funnel', 'Experiment', 'Variantion', 'Goal', 'Attribution', 'DataSource', 'Sync', 'Snapshot'],
];

/** Column shapes, weighted by how common they are in a real table. */
$columnKinds = [
    ['string', 'name', "\$table->string('%s')"],
    ['string', 'title', "\$table->string('%s')"],
    ['string', 'reference', "\$table->string('%s', 64)->nullable()"],
    ['string', 'slug', "\$table->string('%s')->unique()"],
    ['text', 'description', "\$table->text('%s')->nullable()"],
    ['text', 'notes', "\$table->text('%s')->nullable()"],
    ['integer', 'position', "\$table->integer('%s')->default(0)"],
    ['integer', 'quantity', "\$table->unsignedInteger('%s')->default(0)"],
    ['decimal', 'amount', "\$table->decimal('%s', 12, 2)->default(0)"],
    ['decimal', 'rate', "\$table->decimal('%s', 8, 4)->nullable()"],
    ['boolean', 'is_active', "\$table->boolean('%s')->default(true)"],
    ['boolean', 'is_archived', "\$table->boolean('%s')->default(false)"],
    ['date', 'starts_on', "\$table->date('%s')->nullable()"],
    ['datetime', 'processed_at', "\$table->timestamp('%s')->nullable()"],
    ['json', 'metadata', "\$table->json('%s')->nullable()"],
    ['json', 'settings', "\$table->json('%s')->nullable()"],
    ['uuid', 'public_id', "\$table->uuid('%s')->nullable()"],
    ['string', 'status', "\$table->string('%s', 32)->default('draft')"],
    ['string', 'external_id', "\$table->string('%s')->nullable()->unique()"],
    ['float', 'score', "\$table->float('%s')->nullable()"],
];

/** Casts the model should declare, keyed by the column kind that earns one. */
$castFor = [
    'json' => 'array',
    'boolean' => 'boolean',
    'datetime' => 'datetime',
    'date' => 'date',
    'decimal' => 'decimal:2',
];

/**
 * Tables the framework and the Testbench skeleton already own. A generated
 * model that claimed one of these would fail the migration — `Session` is the
 * one that actually bites.
 */
const RESERVED_TABLES = [
    'migrations', 'users', 'password_reset_tokens', 'password_resets', 'sessions',
    'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'notifications',
    'personal_access_tokens',
];

function pick(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

/** Model names stay natural; only the table is qualified when it has to be. */
function tableFor(string $name, string $domain): string
{
    $table = Str::snake(Str::pluralStudly($name));

    return in_array($table, RESERVED_TABLES, true)
        ? Str::snake($domain).'_'.$table
        : $table;
}

/*
|--------------------------------------------------------------------------
| Model list
|--------------------------------------------------------------------------
|
| Domains are filled round-robin so the graph has clusters of related models
| rather than one uniform blob, and so a smaller count still spans domains.
*/

$models = [];
$used = [];
$domainNames = array_keys($domains);
$cursor = array_fill_keys($domainNames, 0);

while (count($models) < $count) {
    $progressed = false;

    foreach ($domainNames as $domain) {
        if (count($models) >= $count) {
            break;
        }

        $pool = $domains[$domain];
        $index = $cursor[$domain]++;

        if (! isset($pool[$index])) {
            continue;
        }

        $progressed = true;
        $name = $pool[$index];

        // Pools are hand-written, but a collision would be a silent overwrite.
        if (isset($used[$name])) {
            $name = $domain.$name;
        }

        $used[$name] = true;

        $models[] = [
            'name' => $name,
            'domain' => $domain,
            'table' => tableFor($name, $domain),
            'columns' => [],
            'relations' => [],
            'fks' => [],
        ];
    }

    // Every pool is exhausted: the requested count is more than the vocabulary
    // can name, so wrap round with a numbered suffix rather than looping for
    // ever.
    if (! $progressed) {
        $round = 2;
        while (count($models) < $count) {
            foreach ($domainNames as $domain) {
                foreach ($domains[$domain] as $noun) {
                    if (count($models) >= $count) {
                        break 2;
                    }

                    $name = $noun.$round;
                    $used[$name] = true;

                    $models[] = [
                        'name' => $name,
                        'domain' => $domain,
                        'table' => tableFor($name, $domain),
                        'columns' => [],
                        'relations' => [],
                        'fks' => [],
                    ];
                }
            }
            $round++;
        }
    }
}

$byName = [];
foreach ($models as $i => $model) {
    $byName[$model['name']] = $i;
}

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
*/

foreach ($models as $i => &$model) {
    $take = mt_rand(4, 12);
    $chosen = [];

    for ($n = 0; $n < $take; $n++) {
        [$kind, $columnName, $definition] = pick($columnKinds);

        if (isset($chosen[$columnName])) {
            continue;
        }

        $chosen[$columnName] = true;
        $model['columns'][] = ['name' => $columnName, 'kind' => $kind, 'definition' => $definition];
    }
}
unset($model);

/*
|--------------------------------------------------------------------------
| Relations
|--------------------------------------------------------------------------
|
| belongsTo points at an *earlier* model so the migration can create tables in
| order with real foreign keys. Targets are usually inside the same domain,
| which is what gives the graph its clusters.
*/

foreach ($models as $i => &$model) {
    if ($i === 0) {
        continue;
    }

    $links = min($i, mt_rand(0, 3));

    for ($n = 0; $n < $links; $n++) {
        $sameDomain = array_values(array_filter(
            array_slice($models, 0, $i),
            fn ($candidate) => $candidate['domain'] === $model['domain'],
        ));

        // 70% same domain when there is one to point at, otherwise anywhere.
        $target = ($sameDomain && mt_rand(1, 100) <= 70)
            ? pick($sameDomain)
            : $models[mt_rand(0, $i - 1)];

        $foreign = Str::snake($target['name']).'_id';

        if (isset($model['fks'][$foreign])) {
            continue;
        }

        $nullable = mt_rand(1, 100) <= 40;
        $model['fks'][$foreign] = ['table' => $target['table'], 'nullable' => $nullable];

        $model['relations'][] = [
            'type' => 'belongsTo',
            'method' => Str::camel($target['name']),
            'target' => $target['name'],
            'foreign' => $foreign,
        ];

        // The inverse, so the graph is not all arrows in one direction.
        $models[$byName[$target['name']]]['relations'][] = [
            'type' => mt_rand(1, 100) <= 15 ? 'hasOne' : 'hasMany',
            'method' => mt_rand(1, 100) <= 15
                ? Str::camel($model['name'])
                : Str::camel(Str::pluralStudly($model['name'])),
            'target' => $model['name'],
            'foreign' => $foreign,
        ];
    }

    // A tree here and there: self-reference is a case worth having on screen.
    if (mt_rand(1, 100) <= 8) {
        $model['fks']['parent_id'] = ['table' => $model['table'], 'nullable' => true];
        $model['relations'][] = ['type' => 'belongsTo', 'method' => 'parent', 'target' => $model['name'], 'foreign' => 'parent_id'];
        $model['relations'][] = ['type' => 'hasMany', 'method' => 'children', 'target' => $model['name'], 'foreign' => 'parent_id'];
    }
}
unset($model);

/*
|--------------------------------------------------------------------------
| Many-to-many
|--------------------------------------------------------------------------
*/

$pivots = [];
$pairs = max(1, (int) round(count($models) * 0.25));

for ($n = 0; $n < $pairs; $n++) {
    $left = $models[mt_rand(0, count($models) - 1)];
    $right = $models[mt_rand(0, count($models) - 1)];

    if ($left['name'] === $right['name']) {
        continue;
    }

    // Laravel's convention: the two singulars, alphabetically.
    $names = [Str::snake($left['name']), Str::snake($right['name'])];
    sort($names);
    $pivot = implode('_', $names);

    if (isset($pivots[$pivot])) {
        continue;
    }

    $pivots[$pivot] = [
        'left' => $names[0].'_id',
        'right' => $names[1].'_id',
        'leftTable' => $names[0] === Str::snake($left['name']) ? $left['table'] : $right['table'],
        'rightTable' => $names[0] === Str::snake($left['name']) ? $right['table'] : $left['table'],
    ];

    $models[$byName[$left['name']]]['relations'][] = [
        'type' => 'belongsToMany',
        'method' => Str::camel(Str::pluralStudly($right['name'])),
        'target' => $right['name'],
        'pivot' => $pivot,
    ];

    $models[$byName[$right['name']]]['relations'][] = [
        'type' => 'belongsToMany',
        'method' => Str::camel(Str::pluralStudly($left['name'])),
        'target' => $left['name'],
        'pivot' => $pivot,
    ];
}

/*
|--------------------------------------------------------------------------
| Polymorphic
|--------------------------------------------------------------------------
|
| Two models everything can hang off, which is what a real schema looks like
| and what makes the dashed edges worth having.
*/

$polymorphs = [
    ['name' => 'HugeAttachment', 'table' => 'huge_attachments', 'morph' => 'attachable', 'method' => 'attachments'],
    ['name' => 'HugeComment', 'table' => 'huge_comments', 'morph' => 'commentable', 'method' => 'comments'],
];

foreach ($polymorphs as $poly) {
    $models[] = [
        'name' => $poly['name'],
        'domain' => 'Shared',
        'table' => $poly['table'],
        'columns' => [
            ['name' => 'body', 'kind' => 'text', 'definition' => "\$table->text('%s')"],
            ['name' => 'metadata', 'kind' => 'json', 'definition' => "\$table->json('%s')->nullable()"],
        ],
        'relations' => [['type' => 'morphTo', 'method' => $poly['morph']]],
        'fks' => [],
        'morphs' => $poly['morph'],
    ];
    $byName[$poly['name']] = count($models) - 1;
}

foreach ($models as $i => &$model) {
    if (isset($model['morphs'])) {
        continue;
    }

    foreach ($polymorphs as $poly) {
        if (mt_rand(1, 100) <= 22) {
            $model['relations'][] = [
                'type' => 'morphMany',
                'method' => $poly['method'],
                'target' => $poly['name'],
                'morph' => $poly['morph'],
            ];
        }
    }
}
unset($model);

/*
|--------------------------------------------------------------------------
| Unique method names
|--------------------------------------------------------------------------
|
| Two relations can land on the same name — a hasMany and a belongsToMany to
| the same model, say — and PHP will not redeclare a method. Suffix the later
| ones rather than dropping them: the duplicate pair is itself a case worth
| having in the graph, since the viewer keys edge ids on the relation name.
*/

foreach ($models as &$model) {
    $seen = [];

    foreach ($model['relations'] as &$relation) {
        $name = $relation['method'];

        if (isset($seen[$name])) {
            $n = 2;
            while (isset($seen[$name.$n])) {
                $n++;
            }
            $name .= $n;
            $relation['method'] = $name;
        }

        $seen[$name] = true;
    }
    unset($relation);
}
unset($model);

/*
|--------------------------------------------------------------------------
| Emit
|--------------------------------------------------------------------------
*/

// A regeneration replaces the previous set outright: a leftover model from a
// bigger run would linger in the graph with no table behind it.
foreach ([$modelDir, $migrationDir] as $directory) {
    if (is_dir($directory)) {
        foreach (glob($directory.'/*.php') as $file) {
            unlink($file);
        }
    } else {
        mkdir($directory, 0755, true);
    }
}

$relationClasses = [
    'belongsTo' => 'BelongsTo',
    'hasMany' => 'HasMany',
    'hasOne' => 'HasOne',
    'belongsToMany' => 'BelongsToMany',
    'morphMany' => 'MorphMany',
    'morphTo' => 'MorphTo',
];

foreach ($models as $model) {
    $imports = ['Illuminate\Database\Eloquent\Model'];
    $methods = [];
    $casts = [];

    foreach ($model['columns'] as $column) {
        if (isset($castFor[$column['kind']])) {
            $casts[$column['name']] = $castFor[$column['kind']];
        }
    }

    foreach ($model['relations'] as $relation) {
        $class = $relationClasses[$relation['type']];
        $imports[] = 'Illuminate\Database\Eloquent\Relations\\'.$class;

        $body = match ($relation['type']) {
            'belongsTo' => sprintf("return \$this->belongsTo(%s::class, '%s');", $relation['target'], $relation['foreign']),
            'hasMany', 'hasOne' => sprintf("return \$this->%s(%s::class, '%s');", $relation['type'], $relation['target'], $relation['foreign']),
            'belongsToMany' => sprintf("return \$this->belongsToMany(%s::class, '%s');", $relation['target'], $relation['pivot']),
            'morphMany' => sprintf("return \$this->morphMany(%s::class, '%s');", $relation['target'], $relation['morph']),
            'morphTo' => 'return $this->morphTo();',
        };

        $methods[] = sprintf(
            "    public function %s(): %s\n    {\n        %s\n    }",
            $relation['method'],
            $class,
            $body,
        );
    }

    $imports = array_values(array_unique($imports));
    sort($imports);

    $useLines = implode("\n", array_map(fn ($import) => "use {$import};", $imports));

    // Half the models declare a fillable set, so the viewer's "guarded" flag
    // has something to say on the other half.
    $guard = mt_rand(1, 100) <= 50
        ? sprintf(
            "    protected \$fillable = [%s];",
            implode(', ', array_map(fn ($c) => "'".$c['name']."'", array_slice($model['columns'], 0, max(1, (int) floor(count($model['columns']) / 2))))),
        )
        : '    protected $guarded = [];';

    $castBlock = $casts
        ? sprintf(
            "\n    protected function casts(): array\n    {\n        return [%s];\n    }\n",
            implode(', ', array_map(fn ($k, $v) => "'{$k}' => '{$v}'", array_keys($casts), $casts)),
        )
        : '';

    $hidden = mt_rand(1, 100) <= 20 && $model['columns']
        ? sprintf("\n    protected \$hidden = ['%s'];\n", $model['columns'][0]['name'])
        : '';

    // Assembled line by line rather than as one heredoc: the class members are
    // already indented, and a heredoc's closing-marker de-indent would strip
    // that back off again.
    $body = "<?php\n\n";
    $body .= "namespace Workbench\\App\\Huge;\n\n";
    $body .= $useLines."\n\n";
    $body .= "class {$model['name']} extends Model\n{\n";
    $body .= "    protected \$table = '{$model['table']}';\n\n";
    $body .= $guard."\n";
    $body .= $hidden;
    $body .= $castBlock;
    $body .= $methods ? "\n".implode("\n\n", $methods)."\n}\n" : "}\n";

    file_put_contents($modelDir.'/'.$model['name'].'.php', $body);
}

/*
| The migration: every table, then every pivot.
*/

$up = [];

foreach ($models as $model) {
    $lines = ["            \$table->id();"];

    foreach ($model['fks'] as $column => $fk) {
        $lines[] = sprintf(
            "            \$table->foreignId('%s')%s->constrained('%s')%s;",
            $column,
            $fk['nullable'] ? '->nullable()' : '',
            $fk['table'],
            $fk['nullable'] ? '->nullOnDelete()' : '->cascadeOnDelete()',
        );
    }

    foreach ($model['columns'] as $column) {
        $lines[] = '            '.sprintf($column['definition'], $column['name']).';';
    }

    if (isset($model['morphs'])) {
        $lines[] = sprintf("            \$table->morphs('%s');", $model['morphs']);
    }

    $lines[] = "            \$table->timestamps();";

    $up[] = sprintf(
        "        Schema::create('%s', function (Blueprint \$table) {\n%s\n        });",
        $model['table'],
        implode("\n", $lines),
    );
}

foreach ($pivots as $pivot => $spec) {
    $up[] = sprintf(
        "        Schema::create('%s', function (Blueprint \$table) {\n".
        "            \$table->id();\n".
        "            \$table->foreignId('%s')->constrained('%s')->cascadeOnDelete();\n".
        "            \$table->foreignId('%s')->constrained('%s')->cascadeOnDelete();\n".
        "        });",
        $pivot,
        $spec['left'],
        $spec['leftTable'],
        $spec['right'],
        $spec['rightTable'],
    );
}

$tables = array_map(fn ($model) => $model['table'], $models);
$dropList = implode(",\n            ", array_map(fn ($t) => "'".$t."'", array_merge(array_keys($pivots), array_reverse($tables))));

$upBody = implode("\n\n", $up);
$modelCount = count($models);
$relationCount = array_sum(array_map(fn ($m) => count($m['relations']), $models));

$migration = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the generated development fixture — {$modelCount} models,
 * {$relationCount} relations.
 *
 * Generated by workbench/scripts/generate-huge-models.php. Do not edit: run the
 * generator again instead.
 */
return new class extends Migration
{
    public function up(): void
    {
{$upBody}
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            {$dropList},
        ] as \$table) {
            Schema::dropIfExists(\$table);
        }

        Schema::enableForeignKeyConstraints();
    }
};

PHP;

file_put_contents($migrationDir.'/0001_01_01_000001_create_huge_tables.php', $migration);

printf(
    "Generated %d models and %d relations (%d pivot tables), seed %d.\n",
    $modelCount,
    $relationCount,
    count($pivots),
    $seed,
);
printf("  models:    workbench/app/Huge\n");
printf("  migration: workbench/database/migrations-huge\n\n");
printf("Next: composer serve:huge\n");
