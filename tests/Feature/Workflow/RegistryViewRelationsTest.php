<?php

use Flowra\Support\RegistryViewResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Attachment;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Registry;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Workflows\TicketWorkflow\TicketWorkflow;

beforeEach(function () {
    config([
        'flowra.cache_workflows' => false,
        'flowra.models.registry' => Registry::class,
    ]);

    RegistryViewResolver::flush();

    Schema::create('tickets', fn (Blueprint $table) => $table->id());
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->string('body');
    });
    Schema::create('attachments', function (Blueprint $table) {
        $table->id();
        $table->uuid('registry_id');
        $table->string('name');
    });

    foreach ([7 => 'Applicant', 8 => 'Reviewer', 9 => 'Closer', 50 => 'Review Committee'] as $id => $name) {
        User::query()->create(['id' => $id, 'name' => $name]);
    }

    Note::query()->create(['user_id' => 8, 'body' => 'Scored against the rubric']);

    $ticket = Ticket::query()->create();
    $this->workflow = new TicketWorkflow($ticket);

    // submitted (leaf) → under_review (in_review + scored) → closed (leaf)
    $rows = [];

    foreach ([
        ['submit', 'draft', 'submitted', 7],
        ['start_review', 'submitted', 'in_review', 7],
        ['score', 'in_review', 'scored', 8],
        ['close', 'scored', 'closed', 9],
    ] as $minute => [$transition, $from, $to, $actor]) {
        $rows[$transition] = Registry::query()->create([
            'owner_type' => $ticket->getMorphClass(),
            'owner_id' => $ticket->getKey(),
            'workflow' => TicketWorkflow::class,
            'transition' => $transition,
            'from' => $from,
            'to' => $to,
            'applied_by' => $actor,
            'created_at' => now()->addMinutes($minute),
        ]);
    }

    Attachment::query()->create(['registry_id' => $rows['score']->getKey(), 'name' => 'score-sheet.pdf']);
});

$names = fn (iterable $entries, string $relation = 'actor') => collect($entries)
    ->map(fn ($entry) => $entry->relations[$relation]?->name)
    ->all();

it('resolves an actor relation on every entry, phases included', function () use ($names) {
    $entries = $this->workflow->registryView()->collapsed()->with('actor', 'actorNotes')->get();

    expect($names($entries))->toBe(['Applicant', 'Reviewer', 'Closer'])
        ->and($entries[1]->isCollapsed())->toBeTrue()
        ->and($names($entries[1]->children))->toBe(['Applicant', 'Reviewer'])
        ->and($entries[1]->relations['actorNotes']->pluck('body')->all())->toBe(['Scored against the rubric']);
});

it('loads an actor relation in one query whatever the number of entries', function () {
    DB::enableQueryLog();

    $this->workflow->registryView()->collapsed()->with('actor')->get();

    $userQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "users"'));

    expect($userQueries)->toHaveCount(1);
});

it('resolves an actor relation against a mask stand-in, never the hidden actor', function () use ($names) {
    DB::enableQueryLog();

    $entries = $this->workflow->registryView()
        ->collapsed()
        ->maskPhase('under_review', 50)
        ->with('actor', 'actorNotes')
        ->get();

    $phase = $entries[1];

    expect($phase->isRedacted())->toBeTrue()
        ->and($phase->relations['actor']->name)->toBe('Review Committee')
        ->and($names($phase->children))->toBe(['Review Committee', 'Review Committee'])
        // Reviewer 8 wrote a note; the stand-in did not.
        ->and($phase->relations['actorNotes'])->toBeEmpty();

    // The hidden reviewer is never selected — neither as a user nor as a note author. Integer
    // keys are inlined by whereIntegerInRaw(), other keys travel as bindings.
    $selected = collect(DB::getQueryLog())
        ->filter(fn (array $query) => preg_match('/from "(users|notes)"/', $query['query']))
        ->flatMap(function (array $query) {
            preg_match('/ in \(([^)]*)\)/', $query['query'], $in);

            return [...array_map('trim', explode(',', $in[1] ?? '')), ...$query['bindings']];
        })
        ->map(fn ($id) => (string) $id)
        ->all();

    expect($selected)->toContain('50')->not->toContain('8');
});

it('gives a stand-in key the relation\'s empty value', function () {
    $entries = $this->workflow->registryView()
        ->collapsed()
        ->maskPhase('under_review', 'review_committee')
        ->with('actor', 'actorNotes')
        ->get();

    expect($entries[1]->relations['actor'])->toBeNull()
        ->and($entries[1]->relations['actorNotes'])->toBeInstanceOf(EloquentCollection::class)->toBeEmpty()
        ->and($entries[1]->children[1]->relations['actor'])->toBeNull()
        ->and($entries[0]->relations['actor']->name)->toBe('Applicant');
});

it('keeps row relations on leaves beside nested actor relations', function () {
    $entries = $this->workflow->registryView()->collapsed()->with('files', 'actor.notes')->get();

    $scored = $entries[1]->children[1];

    expect($entries[1]->relations)->not->toHaveKey('files')
        ->and($scored->relations['files']->pluck('name')->all())->toBe(['score-sheet.pdf'])
        ->and($scored->relations['actor']->relationLoaded('notes'))->toBeTrue()
        ->and($scored->relations['actor']->notes)->toHaveCount(1);
});

it('honours phase masks when paginating in SQL', function () {
    $page = $this->workflow->registryView()
        ->detailed()
        ->maskPhase('under_review', 50)
        ->with('actor')
        ->paginate(10);

    $actors = collect($page->items())
        ->map(fn ($entry) => [$entry->appliedBy, $entry->relations['actor']?->name])
        ->all();

    expect($actors)->toBe([
        [7, 'Applicant'],
        [50, 'Review Committee'],
        [50, 'Review Committee'],
        [9, 'Closer'],
    ]);
});

it('serializes actor relations on phases and their children', function () {
    $payload = $this->workflow->registryView()->collapsed()->with('actor')->toArray();

    expect($payload[1]['relations']['actor']['name'])->toBe('Reviewer')
        ->and($payload[1]['children'][0]['relations']['actor']['name'])->toBe('Applicant');
});

it('refuses a MorphTo over applied_by', function () {
    $this->workflow->registryView()->with('actorMorph')->get();
})->throws(InvalidArgumentException::class);
