<?php

/**
 * Looks up the server administrator's custom logo (Tools & Settings > Logo), falling back to the Plesk logo.
 *
 * Plesk keeps the logo ID in misc.logo_id and the stored file name in Logos.fake; the file is served
 * from /images/logos/. Plesk fires no event when the logo changes, so it is read on every call:
 * two primary-key lookups.
 */
class Modules_AdditionalAdministratorManager_Branding
{
    const DEFAULT_LOGO_URL = '/cp/theme/images/logos/plesk/logo.svg';
    const LOGO_URL = '/images/logos/';
    const LOGO_DIR = '/usr/local/psa/admin/htdocs/images/logos/';

    public static function logoUrl()
    {
        try {
            $file = pm_Bootstrap::getDbAdapter()->fetchOne(
                "SELECT l.fake FROM misc m JOIN Logos l ON l.id = m.val WHERE m.param = 'logo_id'"
            );
        } catch (Exception $e) {
            pm_Log::err('additional-administrator-manager: unable to read the custom logo: ' . $e->getMessage());
            return self::DEFAULT_LOGO_URL;
        }

        // Only a plain file name that exists, so a stale or odd row never yields a broken image or a foreign path.
        if (!is_string($file) || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $file) || !is_file(self::LOGO_DIR . $file)) {
            return self::DEFAULT_LOGO_URL;
        }
        return self::LOGO_URL . rawurlencode($file);
    }
}
