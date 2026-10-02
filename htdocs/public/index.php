<?php
// Public entry point (no Plesk login required) for the invited administrator setup page.
require_once('sdk.php');
pm_Context::init('additional-administrator-manager');

(new Modules_AdditionalAdministratorManager_SetupPage())->run();
