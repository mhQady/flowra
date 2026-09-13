<?php

namespace Flowra\Enums;

/**
 * The shape a registry view renders its rows in.
 *
 * DETAILED  — one entry per registry row (the raw, unmodified audit trail).
 * COLLAPSED — consecutive rows sharing a phase key become a single parent entry.
 */
enum RegistryShapeEnum: string
{
    use BaseEnum;

    case DETAILED = 'detailed';
    case COLLAPSED = 'collapsed';
}
