<?php

/**
 * Sends plain-text email from the address configured for Plesk notifications:
 *
 *   [notification]
 *   senderAddress = plesk@example.com
 *
 * Falls back to no-reply@<server host name> when that option is missing or invalid.
 */
class Modules_AdditionalAdministratorManager_Mailer
{
    const PANEL_INI = '/usr/local/psa/admin/conf/panel.ini';

    public static function hostname()
    {
        return Modules_AdditionalAdministratorManager_PanelUrl::hostname();
    }

    public static function senderAddress()
    {
        $address = null;
        if (is_readable(self::PANEL_INI)) {
            $ini = @parse_ini_file(self::PANEL_INI, true, INI_SCANNER_RAW);
            if (isset($ini['notification']['senderAddress'])) {
                $address = trim($ini['notification']['senderAddress'], " \t\"'");
            }
        }
        return self::isValidAddress($address) ? $address : 'no-reply@' . self::hostname();
    }

    public static function isValidAddress($address)
    {
        return is_string($address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && !preg_match('/[\r\n]/', $address);
    }

    public static function send($to, $subject, $body)
    {
        if (!self::isValidAddress($to)) {
            throw new pm_Exception(pm_Locale::lmsg('errorEmailInvalid'));
        }
        $from = self::senderAddress();

        $headers = implode("\r\n", [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ]);
        $subject = mb_encode_mimeheader(self::singleLine($subject), 'UTF-8', 'Q');
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (!mail($to, $subject, $body, $headers, '-f' . $from)) {
            throw new pm_Exception(pm_Locale::lmsg('errorEmailNotSent', ['email' => $to]));
        }
    }

    /**
     * Strips line breaks and other control characters from a value placed in a header or template line.
     */
    public static function singleLine($value)
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value));
    }
}
