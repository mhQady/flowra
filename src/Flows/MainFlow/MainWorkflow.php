<?php

namespace Flowra\Flows\MainFlow;

use Flowra\Attributes\TransitionMeta;
use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\HasSubflowContract;
use Flowra\DTOs\{Subflow, Transition};
use Flowra\Flows\FillAppDataWorkflow\{FillAppDataWorkflow, FillAppDataWorkflowStates};

class MainWorkflow extends BaseWorkflow implements HasSubflowContract
{
    #[TransitionMeta(title: 'الإرسال لإعتماد المكتب الهندسي / Sending for EngOffice Credence')]
    public function draftingTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_engoffice_credence',
            from: MainWorkflowStates::INIT,
            to: MainWorkflowStates::DRAFT,
        );
    }

    #[TransitionMeta(title: 'الإرسال لإعتماد المكتب الهندسي / Sending for EngOffice Credence')]
    public function sendingForEngofficeCredenceTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_engoffice_credence',
            from: MainWorkflowStates::DRAFT,
            to: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المساح / Cancelling by Surveyor')]
    public function cancellingBySurveyorWhileCreatingTransition(): Transition
    {
        return $this->t(
            key: 'cancelling_by_surveyor_while_creating',
            from: MainWorkflowStates::PREPARE_APPLICATION_INFO,
            to: MainWorkflowStates::CANCELLED_BY_SURVEYOR,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المساح / Cancelling by Surveyor')]
    public function cancellingBySurveyorWhileEditingTransition(): Transition
    {
        return $this->t(
            key: 'cancelling_by_surveyor_while_editing',
            from: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
            to: MainWorkflowStates::CANCELLED_BY_SURVEYOR,
        );
    }

    #[TransitionMeta(title: 'الإرسال للتدقيق / Sending for Auditing')]
    public function sendingForAuditingTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_auditing',
            from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainWorkflowStates::READY_FOR_AUDITING,
        );
    }

    #[TransitionMeta(title: 'قيد الإلغاء من قبل المكتب الهندسي / Cancelling by EngOffice')]
    public function cancellingByEngofficeTransition(): Transition
    {
        return $this->t(
            key: 'cancelling_by_engoOffice',
            from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainWorkflowStates::CANCELLED_BY_ENGOFFICE,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المكتب الهندسي للمساح للمراجعة / EngOffice Send Back To Surveyor For Revision')]
    public function engofficeSendBackToSurveyorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'engoffice_send_back_to_surveyor_for_revision',
            from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'التعيين للمدقق / Assigning to Auditor')]
    public function assigningToAuditorTransition(): Transition
    {
        return $this->t(
            key: 'assigning_to_auditor',
            from: MainWorkflowStates::READY_FOR_AUDITING,
            to: MainWorkflowStates::UNDER_AUDITING,
        );
    }

    #[TransitionMeta(title: 'الإرسال للمعالجة / Sending for Processing')]
    public function sendingForProcessingTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_processing',
            from: MainWorkflowStates::UNDER_AUDITING,
            to: MainWorkflowStates::READY_FOR_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'الإرسال للمعالجة / Sending for Processing (to active processor)')]
    public function sendingForProcessingToActiveProcessorTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_processing_to_active_processor',
            from: MainWorkflowStates::UNDER_AUDITING,
            to: MainWorkflowStates::UNDER_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'قيد التدقيق / Under auditing (send to active auditor)')]
    public function sendingForAuditToActiveAuditorTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_audit_to_active_auditor',
            from: MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE,
            to: MainWorkflowStates::UNDER_AUDITING,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المدقق للمساح للمراجعة / Auditor Send Back To Surveyor For Revision')]
    public function auditorSendBackToSurveyorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'auditor_send_back_to_surveyor_for_revision',
            from: MainWorkflowStates::UNDER_AUDITING,
            to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإسناد الى معالج / Assigning to Processor')]
    public function assigningToProcessorTransition(): Transition
    {
        return $this->t(
            key: 'assigning_to_processor',
            from: MainWorkflowStates::READY_FOR_PROCESSING,
            to: MainWorkflowStates::UNDER_PROCESSING,
        );
    }

    #[TransitionMeta(title: 'الإرسال لمراجعة مدير العمليات / Sending for Operations Manager Revision')]
    public function sendingForOperationsManagerRevisionTransition(): Transition
    {
        return $this->t(
            key: 'sending_for_operations_manager_revision',
            from: MainWorkflowStates::UNDER_PROCESSING,
            to: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المعالج للمساح للمراجعة / Processor Send Back To Surveyor For Revision')]
    public function processorSendBackToSurveyorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'processor_send_back_to_surveyor_for_revision',
            from: MainWorkflowStates::UNDER_PROCESSING,
            to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من المعالج للمدقق للمراجعة / Processor Send Back To Auditor For Revision')]
    public function processorSendBackToAuditorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'processor_send_back_to_auditor_for_revision',
            from: MainWorkflowStates::UNDER_PROCESSING,
            to: MainWorkflowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'إصدار فاتورة / Issuing Invoice')]
    public function issuingInvoiceTransition(): Transition
    {
        return $this->t(
            key: 'issuing_invoice',
            from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainWorkflowStates::WAITING_FOR_INVOICE_PAYMENT,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمساح للمراجعة / Operations Manager Send Back To Surveyor For Revision')]
    public function operationsManagerSendBackToSurveyorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_surveyor_for_revision',
            from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمدقق للمراجعة / Operations Manager Send Back To Auditor For Revision')]
    public function operationsManagerSendBackToAuditorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_auditor_for_revision',
            from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainWorkflowStates::SENT_BACK_TO_AUDITOR_FOR_REVISION,
        );
    }

    #[TransitionMeta(title: 'الإرسال من مدير العمليات للمعالج للمراجعة / Operations Manager Send Back To Processor For Revision')]
    public function operationsManagerSendBackToProcessorForRevisionTransition(): Transition
    {
        return $this->t(
            key: 'operations_manager_send_back_to_processor_for_revision',
            from: MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION,
            to: MainWorkflowStates::SENT_BACK_TO_PROCESSOR_FOR_REVISION,
        );
    }


    public function defineSubflows(): array
    {
        return [
            Subflow::define()->bind(MainWorkflowStates::DRAFT)
                ->to(FillAppDataWorkflow::class)
                ->start('fillingOwnerData')
                ->exit(FillAppDataWorkflowStates::SENT, 'sendingForEngofficeCredence')
                ->make(),
        ];
    }
}