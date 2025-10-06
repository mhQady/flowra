<?php

namespace Flowra\Flows\FillAppDataWorkflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\BaseWorkflowContract;
use Flowra\DTOs\Transition;

class FillAppDataWorkflow extends BaseWorkflow implements BaseWorkflowContract
{
    /**
     * @return array|Transition[]
     */
    public static function transitionsSchema(): array
    {
        return [
            Transition::make(
                key: 'filling_owner_data',
                from: FillAppDataWorkflowStates::INIT,
                to: FillAppDataWorkflowStates::OWNER_INFO_ENTERED
            ),
            Transition::make(
                key: 'filling_certificates_data',
                from: FillAppDataWorkflowStates::OWNER_INFO_ENTERED,
                to: FillAppDataWorkflowStates::CERTIFICATES_INFO_ENTERED,
            ),
            Transition::make(
                key: 'filling_buildings_data',
                from: FillAppDataWorkflowStates::CERTIFICATES_INFO_ENTERED,
                to: FillAppDataWorkflowStates::BUILDINGS_INFO_ENTERED,
            ),
            Transition::make(
                key: 'filling_inspection_report_data',
                from: FillAppDataWorkflowStates::BUILDINGS_INFO_ENTERED,
                to: FillAppDataWorkflowStates::INSPECTION_REPORT_INFO_ENTERED,
            ),
            Transition::make(
                key: 'sending',
                from: FillAppDataWorkflowStates::INSPECTION_REPORT_INFO_ENTERED,
                to: FillAppDataWorkflowStates::SENT,
            )
        ];
    }
}