<?php

return [

    'workflows_path' => env('FLOWRA_WORKFLOWS_PATH', 'app/workflows'),

    'cache_workflows' => env('FLOWRA_CACHE_WORKFLOWS', false),

    // Null uses the default cache store; set to a store name to override.
    'cache_driver' => env('FLOWRA_CACHE_DRIVER', 'database'),

    // Optional list of workflow class names for cache warming/clearing commands.
    'workflows' => [],

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
