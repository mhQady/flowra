<?php

namespace Flowra\Flows\MainFlow;


use Flowra\DTOs\StateGroup;
use Flowra\Enums\BaseEnum;
use Flowra\Flows\FillAppDataWorkflow\FillAppDataWorkflowStates;

enum MainWorkflowStates: string
{
    use BaseEnum;

    case INIT = 'init';
    case PREPARE_APPLICATION_INFO = 'prepare_application_info';
    case OWNER_INFO_ENTERED = 'owner_info_entered';
    case CERTIFICATES_INFO_ENTERED = 'certificates_info_entered';
    case BUILDINGS_INFO_ENTERED = 'buildings_info_entered';
    case INSPECTION_REPORT_INFO_ENTERED = 'inspection_report_info_entered';
    case SENT = 'sent';
    case CANCELLED_BY_SURVEYOR = 'cancelled_by_surveyor';
    case WAITING_ENGOFFICE_CREDENCE = 'waiting_engoffice_credence';
    case READY_FOR_AUDITING = 'ready_for_auditing';
    case CANCELLED_BY_ENGOFFICE = 'cancelled_by_engoffice';
    case SENT_BACK_TO_SURVEYOR_FOR_REVISION = 'sent_back_to_surveyor_for_revision';
    case UNDER_AUDITING = 'under_auditing';
    case READY_FOR_PROCESSING = 'ready_for_processing';
    case UNDER_PROCESSING = 'under_processing';
    case READY_FOR_OPERATIONS_MANAGER_REVISION = 'ready_for_operations_manager_revision';
    case SENT_BACK_TO_AUDITOR_FOR_REVISION = 'sent_back_to_auditor_for_revision';
    case WAITING_FOR_INVOICE_PAYMENT = 'waiting_for_invoice_payment';
    case SENT_BACK_TO_PROCESSOR_FOR_REVISION = 'sent_back_to_processor_for_revision';

    /**
     * Describe states that act as groups/nodes for nested states.
     *
     * @return array<StateGroup|array>
     */
    public static function groups(): array
    {
        return [
            StateGroup::make(self::PREPARE_APPLICATION_INFO)->children(
                self::OWNER_INFO_ENTERED,
                self::CERTIFICATES_INFO_ENTERED,
                self::BUILDINGS_INFO_ENTERED,
                self::INSPECTION_REPORT_INFO_ENTERED,
                self::SENT,
            ),
        ];
    }
}
