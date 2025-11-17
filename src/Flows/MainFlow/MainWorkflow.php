<?php

namespace Flowra\Flows\MainFlow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\BaseWorkflowContract;
use Flowra\DTOs\Transition;

class MainWorkflow extends BaseWorkflow implements BaseWorkflowContract
{
    /**
     * @return array|Transition[]
     */
    public static function transitionsSchema(): array
    {
        return [
            Transition::make(
                key: 'initiating',
                from: MainWorkflowStates::INIT,
                to: MainWorkflowStates::OWNER_INFO_ENTERED
            ),
            Transition::make(
                key: 'filling_certificates_data',
                from: MainWorkflowStates::OWNER_INFO_ENTERED,
                to: MainWorkflowStates::CERTIFICATES_INFO_ENTERED,
            ),
            Transition::make(
                key: 'filling_buildings_data',
                from: MainWorkflowStates::CERTIFICATES_INFO_ENTERED,
                to: MainWorkflowStates::BUILDINGS_INFO_ENTERED,
            ),
            Transition::make(
                key: 'filling_inspection_report_data',
                from: MainWorkflowStates::BUILDINGS_INFO_ENTERED,
                to: MainWorkflowStates::INSPECTION_REPORT_INFO_ENTERED,
            ),
            Transition::make(
                key: 'sending_for_engoffice_credence',
                from: MainWorkflowStates::INSPECTION_REPORT_INFO_ENTERED,
                to: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
            ),
            Transition::make(
                key: 'cancelling_by_surveyor_while_editing',
                from: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
                to: MainWorkflowStates::CANCELLED_BY_SURVEYOR,
            ),
            Transition::make(
                key: 'sending_for_auditing',
                from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
                to: MainWorkflowStates::READY_FOR_AUDITING,
            ),
            Transition::make(
                key: 'cancelling_by_engOffice',
                from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
                to: MainWorkflowStates::CANCELLED_BY_ENGOFFICE,
            ),
            Transition::make(
                key: 'engoffice_send_back_to_surveyor_for_revision',
                from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
                to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'assigning_to_auditor',
                from: MainWorkflowStates::READY_FOR_AUDITING,
                to: MainWorkflowStates::UNDER_AUDITING,
            ),
            Transition::make(
                key: 'sending_for_processing',
                from: MainWorkflowStates::UNDER_AUDITING,
                to: MainWorkflowStates::READY_FOR_PROCESSING,
            ),
            Transition::make(
                key: 'sending_for_processing_to_active_processor',
                from: MainWorkflowStates::UNDER_AUDITING,
                to: MainWorkflowStates::UNDER_PROCESSING,
            ),
            Transition::make(
                key: 'sending_for_audit_to_active_auditor',
                from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
                to: MainWorkflowStates::UNDER_AUDITING,
            ),
            Transition::make(
                key: 'auditor_send_back_to_surveyor_for_revision',
                from: MainWorkflowStates::UNDER_AUDITING,
                to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'assigning_to_processor',
                from: MainWorkflowStates::READY_FOR_PROCESSING,
                to: MainWorkflowStates::UNDER_PROCESSING,
            ),
            Transition::make(
                key: 'sending_for_operations_manager_revision',
                from: MainWorkflowStates::UNDER_PROCESSING,
                to: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            ),
            Transition::make(
                key: 'processor_send_back_to_surveyor_for_revision',
                from: MainWorkflowStates::UNDER_PROCESSING,
                to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'processor_send_back_to_auditor_for_revision',
                from: MainWorkflowStates::UNDER_PROCESSING,
                to: MainWorkflowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'issuing_invoice',
                from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
                to: MainWorkflowStates::WAITING_FOR_INVOICE_PAYMENT,
            ),
            Transition::make(
                key: 'operations_manager_send_back_to_surveyor_for_revision',
                from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
                to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'operations_manager_send_back_to_auditor_for_revision',
                from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
                to: MainWorkflowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
            ),
            Transition::make(
                key: 'operations_manager_send_back_to_processor_for_revision',
                from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
                to: MainWorkflowStates::SENT_BACK_TO_PROCESSOR_FOR_REVISION,
            ),
        ];
    }
}
