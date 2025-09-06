<?php

namespace Flowra\Flows\MainFlow;

use Flowra\Attributes\TransitionMeta;
use Flowra\DTOs\Transition;
use Flowra\Flows\BaseFlow;

class MainFlow extends BaseFlow
{
    #[TransitionMeta(title: 'إدخال بيانات ملكية العقار / Filling Owner Data')]
    public function fillingOwnerDataTransition(): Transition
    {
        return $this->t(
            key: 'filling_owner_data',
            from: MainFlowStates::INIT,
            to: MainFlowStates::OWNER_INFO_ENTERED,
        );
    }

    #[TransitionMeta(title: 'الإرسال لإعتماد المكتب الهندسي / Sending for EngOffice Credence')]
    public function sendingForEngofficeCredenceTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_engoffice_credence',
            from: MainFlowStates::INSPECTION_REPORT_INFO_ENTERED,
            to: MainFlowStates::WAITING_ENGOFFICE_CREDENCE,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المساح / Cancelling by Surveyor')]
    public function cancellingBySurveyorWhileCreating(): Transition
    {
        return $this->t(
            key: 'cancelling_by_surveyor_while_creating',
            from: MainFlowStates::PREPARE_APPLICATION_INFO,
            to: MainFlowStates::CANCELLED_BY_SURVEYOR,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المساح / Cancelling by Surveyor')]
    public function cancellingBySurveyorWhileEditingTransition(): Transition
    {
        return $this->t(
            key: 'cancelling_by_surveyor_while_editing',
            from: MainFlowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            to: MainFlowStates::CANCELLED_BY_SURVEYOR,
        );
    }

    #[TransitionMeta(title: 'الإرسال للتدقيق / Sending for Auditing')]
    public function sendingForAuditing(): Transition
    {
        return $this->t(
            key: 'sending_for_auditing',
            from: MainFlowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainFlowStates::READY_FOR_AUDITING,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المكتب الهندسي / Cancelling by EngOffice')]
    public function cancellingByEngoffice(): Transition
    {
        return $this->t(
            key: 'cancelling_by_engoOffice',
            from: MainFlowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainFlowStates::CANCELLED_BY_ENGOFFICE,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المكتب الهندسي للمساح للمراجعة / EngOffice Send Back To Surveyor For Revision')]
    public function engofficeSendBackToSurveyorForRevision(): Transition
    {
        return $this->t(
            key: 'engoffice_send_back_to_surveyor_for_revision',
            from: MainFlowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainFlowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'التعيين للمدقق / Assigning to Auditor')]
    public function assigningToAuditor(): Transition
    {
        return $this->t(
            key: 'assigning_to_auditor',
            from: MainFlowStates::READY_FOR_AUDITING,
            to: MainFlowStates::UNDER_AUDITING,
        );
    }

    #[TransitionMeta(title: 'الإرسال للمعالجة / Sending for Processing')]
    public function sendingForProcessing(): Transition
    {
        return $this->t(
            key: 'sending_for_processing',
            from: MainFlowStates::UNDER_AUDITING,
            to: MainFlowStates::READY_FOR_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'الإرسال للمعالجة / Sending for Processing (to active processor)')]
    public function sendingForProcessingToActiveProcessor(): Transition
    {
        return $this->t(
            key: 'sending_for_processing_to_active_processor',
            from: MainFlowStates::UNDER_AUDITING,
            to: MainFlowStates::UNDER_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'قيد التدقيق / Under auditing (send to active auditor)')]
    public function sendingForAuditToActiveAuditor(): Transition
    {
        return $this->t(
            key: 'sending_for_audit_to_active_auditor',
            from: MainFlowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainFlowStates::UNDER_AUDITING,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المدقق للمساح للمراجعة / Auditor Send Back To Surveyor For Revision')]
    public function auditorSendBackToSurveyorForRevision(): Transition
    {
        return $this->t(
            key: 'auditor_send_back_to_surveyor_for_revision',
            from: MainFlowStates::UNDER_AUDITING,
            to: MainFlowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإسناد الى معالج / Assigning to Processor')]
    public function assigningToProcessor(): Transition
    {
        return $this->t(
            key: 'assigning_to_processor',
            from: MainFlowStates::READY_FOR_PROCESSING,
            to: MainFlowStates::UNDER_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'الإرسال لمراجعة مدير العمليات / Sending for Operations Manager Revision')]
    public function sendingForOperationsManagerRevision(): Transition
    {
        return $this->t(
            key: 'sending_for_operations_manager_revision',
            from: MainFlowStates::UNDER_PROCESSING,
            to: MainFlowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المعالج للمساح للمراجعة / Processor Send Back To Surveyor For Revision')]
    public function processorSendBackToSurveyorForRevision(): Transition
    {
        return $this->t(
            key: 'processor_send_back_to_surveyor_for_revision',
            from: MainFlowStates::UNDER_PROCESSING,
            to: MainFlowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المعالج للمدقق للمراجعة / Processor Send Back To Auditor For Revision')]
    public function processorSendBackToAuditorForRevision(): Transition
    {
        return $this->t(
            key: 'processor_send_back_to_auditor_for_revision',
            from: MainFlowStates::UNDER_PROCESSING,
            to: MainFlowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'إصدار فاتورة / Issuing Invoice')]
    public function issuingInvoice(): Transition
    {
        return $this->t(
            key: 'issuing_invoice',
            from: MainFlowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainFlowStates::WAITING_FOR_INVOICE_PAYMENT,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمساح للمراجعة / Operations Manager Send Back To Surveyor For Revision')]
    public function operationsManagerSendBackToSurveyorForRevision(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_surveyor_for_revision',
            from: MainFlowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainFlowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمدقق للمراجعة / Operations Manager Send Back To Auditor For Revision')]
    public function operationsManagerSendBackToAuditorForRevision(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_auditor_for_revision',
            from: MainFlowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainFlowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمعالج للمراجعة / Operations Manager Send Back To Processor For Revision')]
    public function operationsManagerSendBackToProcessorForRevision(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_processor_for_revision',
            from: MainFlowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainFlowStates::SENT_BACK_TO_PROCESSOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'إدخال بيانات الرخص والشهادات / Filling Certificates Data')]
    public function fillingCertificatesData(): Transition
    {
        return $this->t(
            key: 'filling_certificates_data',
            from: MainFlowStates::OWNER_INFO_ENTERED,
            to: MainFlowStates::CERTIFICATES_INFO_ENTERED,
        );
    }

    #[TransitionMeta(title: 'إدخال بيانات المباني / Filling Buildings Data')]
    public function fillingBuildingsData(): Transition
    {
        return $this->t(
            key: 'filling_buildings_data',
            from: MainFlowStates::CERTIFICATES_INFO_ENTERED,
            to: MainFlowStates::BUILDINGS_INFO_ENTERED,
        );
    }

    #[TransitionMeta(title: 'إدخال بيانات تقرير معاينة العقار / Filling Inspection Report Data')]
    public function fillingInspectionReportData(): Transition
    {
        return $this->t(
            key: 'filling_inspection_report_data',
            from: MainFlowStates::BUILDINGS_INFO_ENTERED,
            to: MainFlowStates::INSPECTION_REPORT_INFO_ENTERED,
        );
    }
}