<?php

return [

    'workflows_path' => env('FLOWRA_WORKFLOWS_PATH', 'app/Workflows'),
    'workflows_namespace' => env('FLOWRA_WORKFLOWS_PATH', 'App\\Workflows'),

    'cache_workflows' => env('FLOWRA_CACHE_WORKFLOWS', true),

    // Null uses the default cache store; set to a store name to override.
    'cache_driver' => env('FLOWRA_CACHE_DRIVER', 'database'),

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
