<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflows Path
    |--------------------------------------------------------------------------
    | Path where your workflow classes are stored
    */
    'workflows_path' => env('FLOWRA_WORKFLOWS_PATH', 'app/Workflows'),

    /*
    |--------------------------------------------------------------------------
    | Workflows Namespace
    |--------------------------------------------------------------------------
    | Base namespace used to resolve workflow classes
    */
    'workflows_namespace' => env('FLOWRA_WORKFLOWS_PATH', 'App\\Workflows'),

    /*
    |--------------------------------------------------------------------------
    | Cache Workflows
    |--------------------------------------------------------------------------
    | Toggle caching of parsed workflow definitions
    */
    'cache_workflows' => env('FLOWRA_CACHE_WORKFLOWS', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    | Cache store to use; null uses the default store
    */
    'cache_driver' => env('FLOWRA_CACHE_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    | Table names used by Flowra for statuses and registry
    */
    'tables' => [
        'statuses' => 'statuses',
        'registry' => 'statuses_registry',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Eloquent classes Flowra uses for status/registry rows. Point these at
    | your own classes to add casts, relations, or behavior — they must
    | extend the respective Flowra\Models class.
    */
    'models' => [
        'status' => Flowra\Models\Status::class,
        'registry' => Flowra\Models\Registry::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry Views
    |--------------------------------------------------------------------------
    | Named, read-only projections over the registry rows configured above. A view
    | never changes what is written — it only decides how recorded rows are rendered
    | and which of them a given audience sees. Each view combines a shape with a set
    | of conditions:
    |
    |   'shape'      => 'detailed' (one entry per row) or 'collapsed' (consecutive
    |                   rows landing in the same phase become one entry)
    |   'collapse'   => phases to collapse; omit to collapse all of them
    |   'expand'     => phases to leave expanded, when collapsing all the rest
    |   'conditions' => class-strings implementing RegistryScopeContract (applied in
    |                   SQL) and/or RegistryFilterContract (applied per row in PHP)
    |   'applied_by' => who the entries render as: an actor id, or one of the strategies
    |                   'first' / 'last' / 'sole' for picking one actor out of a collapsed
    |                   run. Overrides whatever the Phase itself declared.
    |
    | Views declared here apply to every workflow; a workflow's own static
    | registryViews() method may add to them and wins on name clashes. The built-in
    | 'default' view is detailed and unconditional — the raw audit trail — and can
    | be overridden here like any other. Asking for a view that is not registered
    | throws RegistryViewNotFoundException rather than quietly returning everything.
    |
    | Conditions declared HERE must be class-strings: `php artisan config:cache`
    | refuses to serialize closures. Put closure conditions on the workflow class,
    | which is never serialized.
    */
    'registry_views' => [

        // View used when registryView() is called with no name.
        'default_view' => env('FLOWRA_REGISTRY_DEFAULT_VIEW', 'default'),

        /*
        | Actor a rendered entry falls back to when nothing else resolves one — rows written
        | by a queue, a console command or a webhook carry no applied_by, and a collapsed
        | entry may deliberately refuse to name one of the several people behind it. It is
        | the id of a user in your own application (a "system" account); null, the default,
        | leaves those entries without an actor exactly as before.
        |
        | This never changes what is written: the row keeps its null, and the entry still
        | exposes it through recordedBy.
        */
        'system_user' => env('FLOWRA_REGISTRY_SYSTEM_USER'),

        'views' => [
            // 'applicant' => [
            //     'shape' => 'collapsed',
            //     'conditions' => [App\Workflows\RegistryViews\HideInternalRows::class],
            //     // Collapse only these phases; omit the key to collapse every phase.
            //     'collapse' => ['under_review'],
            //     // An actor id, or a strategy: 'first', 'last' (default), 'sole'.
            //     'applied_by' => 'sole',
            //     // Hide the real actor behind these phases / transitions and show the
            //     // given stand-in instead — an account id, or a key you translate under
            //     // flowra::flowra.actors. Masks outrank 'applied_by' and drop the
            //     // recorded actor from the rendered entry.
            //     'mask' => [
            //         'phases' => ['under_review' => 'review_committee'],
            //         'transitions' => ['reject' => 'review_committee'],
            //     ],
            // ],
        ],
    ],

//    // Define the workflow stubs directory
//    'stubs_dir' => base_path('stubs/workflow'),
//
//    // Define the workflow schemas directory
//    'schemas_dir' => base_path('database/workflows'),
//
//    // Define the HasWorkflow trait
//    // Using HasWorkflow inside a model it what determine if that model can use workflow or not
//    'has_workflow' => Flowra\Traits\HasWorkflow::class,
];
