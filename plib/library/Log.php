<?php

/**
 * Writes the extension's events (see hooks/ActionLog.php) to the Plesk Action Log.
 *
 * Email addresses and passwords are never logged: a changed email address or password is
 * recorded as a "changed" marker only.
 */
class Modules_AdditionalAdministratorManager_Log
{
    const INVITATION_SENT = 'invitation_sent';
    const INVITATION_RESENT = 'invitation_resent';
    const INVITATION_ACCEPTED = 'invitation_accepted';
    const ACCOUNT_EDITED = 'account_edited';

    /**
     * @param array<string, string> $old Values before the action, keyed by locale key of the field label
     * @param array<string, string> $new Values after the action, keyed by locale key of the field label
     */
    public static function submit($event, array $old = [], array $new = [])
    {
        try {
            // Additional administrators have no object ID available through the SDK or CLI.
            pm_ActionLog::submit($event, 0, self::labelKeys($old), self::labelKeys($new));
        } catch (Exception $e) {
            // The action itself succeeded; a logging failure must not turn it into an error.
            pm_Log::err('Unable to write "' . $event . '" to the Action Log: ' . $e->getMessage());
        }
    }

    public static function clientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    }

    private static function labelKeys(array $values)
    {
        $labelled = [];
        foreach ($values as $key => $value) {
            $labelled[pm_Locale::lmsg($key)] = (string)$value;
        }
        return $labelled;
    }
}
