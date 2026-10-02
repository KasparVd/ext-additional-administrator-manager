<?php

/**
 * Adds the "Administrator Users" item to the Server Management section of the Plesk sidebar,
 * and to the navigation of the hosting panel (Power User view).
 */
class Modules_AdditionalAdministratorManager_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        $button = [
            'title' => pm_Locale::lmsg('navTitle'),
            'description' => pm_Locale::lmsg('navDescription'),
            'icon' => pm_Context::getBaseUrl() . 'images/nav-admin-50.svg',
            'link' => pm_Context::getActionUrl('index', 'index'),
            'contextParams' => true,
        ];

        return [
            $button + [
                'place' => self::PLACE_ADMIN_NAVIGATION,
                'section' => self::SECTION_NAV_SERVER_MANAGEMENT,
            ],
            $button + [
                'place' => self::PLACE_HOSTING_PANEL_NAVIGATION,
                'order' => 9,
            ],
        ];
    }
}
