<?php

namespace Flowra\Flows\FillAppDataWorkflow;

use Flowra\Enums\BaseEnum;

enum FillAppDataWorkflowStates: string
{
    use BaseEnum;

    case INIT = 'init';
    case OWNER_INFO_ENTERED = 'owner_info_entered';
    case CERTIFICATES_INFO_ENTERED = 'certificates_info_entered';
    case BUILDINGS_INFO_ENTERED = 'buildings_info_entered';
    case INSPECTION_REPORT_INFO_ENTERED = 'inspection_report_info_entered';
    case SENT = 'sent';
}