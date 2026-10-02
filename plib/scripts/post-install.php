<?php
// Creates or upgrades the extension's SQLite database on install and upgrade.
pm_Context::init('additional-administrator-manager');

try {
    Modules_AdditionalAdministratorManager_Db::migrate();
} catch (Exception $e) {
    pm_Log::err($e);
    exit(1);
}
