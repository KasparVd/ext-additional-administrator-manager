<?php

/**
 * Interface languages for additional administrators.
 *
 * The languages offered are the ones "plesk bin server_pref --locales-list" reports as available;
 * the first one listed is the default. An account's language applies to Plesk itself (admin_alias
 * -locale) and to the extension's own texts for the invitee: the invitation email and the setup page.
 *
 * The extension has a translation for a language when both its message file
 * (resources/locales/<code>.php) and its invitation template (resources/templates/invitation.<code>.txt)
 * exist. Without them the invitee's texts are in English (en-US).
 */
class Modules_AdditionalAdministratorManager_Locales
{
    const FALLBACK = 'en-US';
    const MESSAGES = 'resources/locales/%s.php';
    const TEMPLATE = 'resources/templates/invitation.%s.txt';

    /** @var array<string, string>|null */
    private static $available = null;

    /** @var array<string, array<string, string>> Messages per language code. */
    private static $messages = [];

    /**
     * @return array<string, string> Language code => name (e.g. 'de-DE' => 'German (Germany)'), in the order Plesk lists them.
     */
    public static function available()
    {
        if (self::$available === null) {
            $result = pm_ApiCli::call('server_pref', ['--locales-list'], pm_ApiCli::RESULT_FULL);
            self::$available = [];
            foreach (preg_split('/\R/', (string)$result['stdout']) as $line) {
                // "de-DE: GERMAN (Germany)"
                if (preg_match('/^\s*([a-z]{2,3}(?:-[A-Za-z0-9]+)*)\s*:\s*(.+?)\s*$/', $line, $m)) {
                    self::$available[$m[1]] = self::displayName($m[2]);
                }
            }
        }
        return self::$available;
    }

    /**
     * The first language Plesk lists.
     */
    public static function defaultLocale()
    {
        $codes = array_keys(self::available());
        return $codes ? $codes[0] : self::FALLBACK;
    }

    /**
     * $code if it is available in Plesk, otherwise the default language.
     */
    public static function availableOrDefault($code)
    {
        return is_string($code) && isset(self::available()[$code]) ? $code : self::defaultLocale();
    }

    /**
     * Interface language of an account: the language set on it, or the default when it has none
     * or its language is no longer available.
     */
    public static function ofAccount($login)
    {
        return self::availableOrDefault(Modules_AdditionalAdministratorManager_AdminAlias::locale($login));
    }

    /**
     * Language of the extension's texts for an account in $code: $code if the extension has a translation, otherwise en-US.
     */
    public static function contentLocale($code)
    {
        return is_string($code) && self::hasTranslation($code) ? $code : self::FALLBACK;
    }

    public static function hasTranslation($code)
    {
        // The code becomes part of a file name.
        if (!preg_match('/^[A-Za-z0-9-]+$/', (string)$code)) {
            return false;
        }
        $dir = pm_Context::getPlibDir();
        return is_file($dir . sprintf(self::MESSAGES, $code)) && is_file($dir . sprintf(self::TEMPLATE, $code));
    }

    /**
     * Path of the invitation email template for the extension's texts in $code (see contentLocale()).
     */
    public static function templatePath($code)
    {
        return pm_Context::getPlibDir() . sprintf(self::TEMPLATE, self::contentLocale($code));
    }

    /**
     * Message $key in the language of the extension's texts for $code, with %%name%% placeholders filled in.
     * Keys missing from a translation fall back to en-US.
     */
    public static function lmsg($code, $key, array $params = [])
    {
        $code = self::contentLocale($code);
        $messages = self::messages($code);
        if (!isset($messages[$key]) && $code !== self::FALLBACK) {
            $messages = self::messages(self::FALLBACK);
        }
        $message = isset($messages[$key]) ? $messages[$key] : $key;

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['%%' . $name . '%%'] = (string)$value;
        }
        return strtr($message, $replacements);
    }

    private static function messages($code)
    {
        if (!isset(self::$messages[$code])) {
            $messages = [];
            $file = pm_Context::getPlibDir() . sprintf(self::MESSAGES, $code);
            if (is_file($file)) {
                include $file;
            }
            self::$messages[$code] = is_array($messages) ? $messages : [];
        }
        return self::$messages[$code];
    }

    /**
     * "GERMAN (Germany)" => "German (Germany)"
     */
    private static function displayName($name)
    {
        return preg_replace_callback('/^[^(]+/u', function ($m) {
            return mb_convert_case(mb_strtolower($m[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }, $name);
    }
}
