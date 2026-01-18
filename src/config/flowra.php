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
