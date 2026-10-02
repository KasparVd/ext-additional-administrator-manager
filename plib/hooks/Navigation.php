<?php

/**
 * Declares the extension's pages so Plesk can build breadcrumbs and page titles.
 */
class Modules_AdditionalAdministratorManager_Navigation extends pm_Hook_Navigation
{
    public function getNavigation()
    {
        return [
            [
                'controller' => 'index',
                'action' => 'index',
                'label' => pm_Locale::lmsg('pageTitle'),
                'pages' => [
                    [
                        'controller' => 'index',
                        'action' => 'invite',
                        'label' => pm_Locale::lmsg('invitePageTitle'),
                    ],
                    [
                        'controller' => 'index',
                        'action' => 'edit',
                        'label' => pm_Locale::lmsg('navEditTitle'),
                    ],
                ],
            ],
        ];
    }
}
