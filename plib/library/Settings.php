<?php

/**
 * Reads the extension settings from panel.ini:
 *
 *   [ext-additional-administrator-manager]
 *   forcePowerUser = true
 *   forceAdminRestrictions = true
 *   allowUserEdit = true
 *   helpArticle = https://example.com/help
 */
class Modules_AdditionalAdministratorManager_Settings
{
    public static function forcePowerUser()
    {
        return self::flag('forcePowerUser', true);
    }

    public static function forceAdminRestrictions()
    {
        return self::flag('forceAdminRestrictions', true);
    }

    public static function allowUserEdit()
    {
        return self::flag('allowUserEdit', true);
    }

    /**
     * @return string URL of the help article linked from the setup page, or '' when unset or not an http(s) URL.
     */
    public static function helpArticle()
    {
        $value = pm_Config::get('helpArticle');
        if (!is_string($value)) {
            return '';
        }

        $url = trim($value, " \t\"'");
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || (string)parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }
        return $url;
    }

    private static function flag($name, $default)
    {
        $value = pm_Config::get($name);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        // panel.ini is parsed as INI, so "true" may arrive as "1" and "false" as "".
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed === null ? $default : $parsed;
    }
}
