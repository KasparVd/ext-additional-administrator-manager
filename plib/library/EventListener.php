<?php

/**
 * Keeps the invitations table in step with changes Plesk makes to additional administrator
 * accounts, including changes made outside the extension (CLI, Plesk's own screens, API).
 *
 * Plesk delivers these events asynchronously, a moment after the change. The ID passed in
 * $objectId is the account's ID in Plesk (admin_aliases.id); it stays the same across renames.
 */
class Modules_AdditionalAdministratorManager_EventListener implements EventListener
{
    public function filterActions()
    {
        return ['admin_alias_create', 'admin_alias_update', 'admin_alias_delete'];
    }

    public function handleEvent($objectType, $objectId, $action, $oldValues, $newValues)
    {
        try {
            pm_Context::init('additional-administrator-manager');
            $aliasId = (int)$objectId;

            switch ($action) {
                case 'admin_alias_create':
                    Modules_AdditionalAdministratorManager_Invitations::onAccountCreated($aliasId, self::value($newValues, 'Login Name'));
                    break;
                case 'admin_alias_update':
                    // Status is a bit mask: 0 means enabled.
                    $enabled = isset($newValues['Status']) && (int)$newValues['Status'] === 0;
                    Modules_AdditionalAdministratorManager_Invitations::onAccountUpdated($aliasId, self::value($newValues, 'Login Name'), $enabled);
                    break;
                case 'admin_alias_delete':
                    Modules_AdditionalAdministratorManager_Invitations::onAccountDeleted($aliasId, self::value($oldValues, 'Login Name'));
                    break;
            }
        } catch (Exception $e) {
            pm_Log::err('additional-administrator-manager: unable to handle ' . $action . ' for account ' . $objectId . ': ' . $e->getMessage());
        }
    }

    private static function value($values, $key)
    {
        return is_array($values) && isset($values[$key]) ? (string)$values[$key] : '';
    }
}

return new Modules_AdditionalAdministratorManager_EventListener();
