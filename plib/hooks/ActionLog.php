<?php

/**
 * Registers the extension's events with the Plesk Action Log (Tools & Settings > Action Log).
 * Plesk stores them as ext_additional-administrator-manager_<event>.
 */
class Modules_AdditionalAdministratorManager_ActionLog extends pm_Hook_ActionLog
{
    public function getEvents()
    {
        return [
            Modules_AdditionalAdministratorManager_Log::INVITATION_SENT => pm_Locale::lmsg('logEventInvitationSent'),
            Modules_AdditionalAdministratorManager_Log::INVITATION_RESENT => pm_Locale::lmsg('logEventInvitationResent'),
            Modules_AdditionalAdministratorManager_Log::INVITATION_ACCEPTED => pm_Locale::lmsg('logEventInvitationAccepted'),
            Modules_AdditionalAdministratorManager_Log::ACCOUNT_EDITED => pm_Locale::lmsg('logEventAccountEdited'),
        ];
    }
}
