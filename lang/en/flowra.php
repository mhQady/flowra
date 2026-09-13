<?php

return [
    'record_not_exist' => 'The record (:model) to which the transition will be attached does not exist',
    'workflow_not_registered_for_model' => 'Workflow (:workflow) is not registered for model (:model)',
    'transition_not_registered_for_workflow' => 'Transition (:transition) is not defined for workflow (:workflow)',
    'transition_not_applicable' => 'Applying transition (:transition) while current state is (:current) is not applicable, Model state must be (:from) so transition can be applied.',
    'state_required_on_jump' => 'State is not valid, state must be of type (:state)',
    'registry_view_not_defined' => 'Registry view (:view) is not defined for workflow (:workflow)',

    /*
     * Labels for collapsed registry entries, keyed by the phase key — the key of the Phase
     * the states belong to. A collapsed entry stands in for the whole phase, so it is named
     * after the phase, never after a single state inside it. Missing keys fall back to a
     * humanized key ("under_review" => "Under Review").
     */
    'phases' => [
        // 'under_review' => 'Under Review',
    ],

    /*
     * Labels for detailed registry entries, keyed by the state the row landed in (`to`) —
     * an entry is named after the status it reached, not the move that got it there. Same
     * humanized fallback ("docs_ok" => "Docs Ok").
     */
    'states' => [
        // 'docs_ok' => 'Documents Verified',
    ],

    /*
     * Labels keyed by transition key. A leaf is named after the state it landed in, so this
     * is consulted only for the one case a state cannot answer: a row that recorded no
     * landing state at all.
     */
    'transitions' => [
        // 'docs_checked' => 'Documents Checked',
    ],

    /*
     * Names for what kind of thing an entry is, keyed by the TransitionTypesEnum case NAME
     * in lower case — the payload carries the case value, but '1' makes a poor lang key.
     * Read through RegistryEntry::typeLabel(), same humanized fallback.
     */
    'types' => [
        // 'transition' => 'Transition',
        // 'reset' => 'Forced Change',
        // 'phase' => 'Step',
    ],

    /*
     * Display names for rendered actors, keyed by whatever the entry renders as — a mask's
     * actor key, or an account id. Read through RegistryEntry::actorLabel(), which returns
     * null for anything not declared here so the host can resolve the id itself.
     *
     * Give the configured system_user an entry and every actorless entry names itself,
     * instead of each UI inventing its own "System" placeholder.
     */
    'actors' => [
        // 'review_committee' => 'Review Committee',
        // '1' => 'System',
    ],
];
