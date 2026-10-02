<?php

/**
 * Thin wrapper around the "plesk bin admin_alias" utility.
 *
 * Passwords are handed over in the PSA_PASSWORD environment variable, never on the
 * command line, so they do not show up in the process list.
 *
 * @see https://docs.plesk.com/en-US/obsidian/cli-linux/using-command-line-utilities/admin_alias-additional-administrator-accounts.72489/
 */
class Modules_AdditionalAdministratorManager_AdminAlias
{
    const UTILITY = 'admin_alias';
    const LOGIN_MAX_LENGTH = 20;

    /**
     * Usernames this extension never creates or renames an account to (exact match, any case),
     * independent of what Plesk itself allows.
     */
    const RESERVED_LOGINS = ['root', 'admin', 'administrator', 'superuser'];

    /**
     * @return string[] Login names of all additional administrators.
     */
    public static function listLogins()
    {
        $output = self::run(['--list']);
        $logins = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $logins[] = $line;
            }
        }
        sort($logins, SORT_NATURAL | SORT_FLAG_CASE);
        return $logins;
    }

    /**
     * @return array<string, array> Details of all additional administrators (login => info()).
     */
    public static function all()
    {
        $accounts = [];
        foreach (self::listLogins() as $login) {
            $accounts[$login] = self::info($login);
        }
        return $accounts;
    }

    /**
     * @return array{login: string, contact: string, email: string, comment: string, enabled: bool}
     */
    public static function info($login)
    {
        $details = ['login' => $login, 'contact' => '', 'email' => '', 'comment' => '', 'enabled' => true];
        $output = self::run(['--info', $login]);

        foreach (preg_split('/\R/', $output) as $line) {
            if (!preg_match('/^\s*([^:]+?)\s*:\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            if ($key === 'enabled') {
                $details['enabled'] = strtolower($m[2]) !== 'false';
            } elseif (strpos($key, 'mail') !== false) {
                $details['email'] = $m[2];
            } elseif (strpos($key, 'contact') !== false) {
                $details['contact'] = $m[2];
            } elseif (strpos($key, 'comment') !== false) {
                $details['comment'] = $m[2];
            }
        }
        return $details;
    }

    /**
     * The account's interface language, or null if none is set (Plesk then uses the server default).
     *
     * admin_alias --info does not show the language, so it is read from Plesk's database,
     * where admin_alias -locale stores it.
     */
    public static function locale($login)
    {
        try {
            $locale = pm_Bootstrap::getDbAdapter()->fetchOne(
                "SELECT p.val FROM admin_aliases a JOIN admin_aliases_param p ON p.alias_id = a.id AND p.param = 'admin_locale' WHERE a.login = ?",
                [$login]
            );
        } catch (Exception $e) {
            return null;
        }
        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    public static function exists($login)
    {
        return in_array($login, self::listLogins(), true);
    }

    /**
     * Returns the additional administrator that uses $email (case-insensitive), or null.
     */
    public static function findLoginByEmail($email)
    {
        $email = strtolower(trim($email));
        foreach (self::listLogins() as $login) {
            if (strtolower(trim(self::info($login)['email'])) === $email) {
                return $login;
            }
        }
        return null;
    }

    /**
     * Whether $email is the primary administrator's profile email (case-insensitive).
     */
    public static function isPrimaryAdminEmail($email)
    {
        $adminEmail = strtolower(trim((string)pm_Client::getAdmin()->getProperty('email')));
        return $adminEmail !== '' && strtolower(trim($email)) === $adminEmail;
    }

    /**
     * Plesk logins are shared by the administrator, additional administrators, resellers and
     * customers, so a login is taken if any of them uses it.
     */
    public static function isLoginTaken($login)
    {
        foreach (self::listLogins() as $existing) {
            if (strcasecmp($existing, $login) === 0) {
                return true;
            }
        }
        try {
            pm_Client::getByLogin($login);
            return true;
        } catch (pm_Exception $e) {
            return false;
        }
    }

    public static function isReservedLogin($login)
    {
        return in_array(strtolower((string)$login), self::RESERVED_LOGINS, true);
    }

    public static function create($login, $password, $email, $contact, $comment, $locale, $enabled = true)
    {
        self::assertNotReserved($login);
        $args = [
            '--create', $login,
            '-passwd', '',
            '-email', $email,
            '-contact', $contact,
            '-locale', $locale,
            '-enabled', $enabled ? 'true' : 'false',
            '-force_power_user', Modules_AdditionalAdministratorManager_Settings::forcePowerUser() ? 'true' : 'false',
            '-force_admin_restrictions', Modules_AdditionalAdministratorManager_Settings::forceAdminRestrictions() ? 'true' : 'false',
        ];
        if ($comment !== '') {
            $args[] = '-comment';
            $args[] = $comment;
        }
        self::run($args, $password);
    }

    /**
     * Blank password, email and contact values and a null locale leave the current value unchanged.
     * The comment is always written so it can be cleared.
     */
    public static function update($login, $password, $email, $contact, $comment, $locale = null)
    {
        $args = ['--update', $login];
        if ($password !== '') {
            $args[] = '-passwd';
            $args[] = '';
        }
        if ($email !== '') {
            $args[] = '-email';
            $args[] = $email;
        }
        if ($contact !== '') {
            $args[] = '-contact';
            $args[] = $contact;
        }
        if ($locale !== null) {
            $args[] = '-locale';
            $args[] = $locale;
        }
        $args[] = '-comment';
        $args[] = $comment;

        self::run($args, $password);
    }

    /**
     * Sets the password chosen by an invited administrator, renames the account if a
     * different username was chosen and enables it, all in one utility call.
     */
    public static function completeInvitation($login, $newLogin, $password)
    {
        self::assertNotReserved($newLogin);
        $args = ['--update', $login];
        if ($newLogin !== $login) {
            $args[] = '-login';
            $args[] = $newLogin;
        }
        $args[] = '-passwd';
        $args[] = '';
        $args[] = '-enabled';
        $args[] = 'true';

        self::run($args, $password);
    }

    private static function assertNotReserved($login)
    {
        if (self::isReservedLogin($login)) {
            throw new pm_Exception(pm_Locale::lmsg('errorLoginReserved'));
        }
    }

    /**
     * Suspends (false) or activates (true) an account's access to Plesk.
     */
    public static function setEnabled($login, $enabled)
    {
        self::run(['--update', $login, '-enabled', $enabled ? 'true' : 'false']);
    }

    public static function remove($login)
    {
        self::run(['--remove', $login]);
    }

    /**
     * @param string $password Passed to the utility in PSA_PASSWORD for an empty -passwd argument,
     *                         and masked out of any error message.
     */
    private static function run(array $args, $password = '')
    {
        $env = $password !== '' ? ['PSA_PASSWORD' => $password] : [];
        try {
            $result = pm_ApiCli::call(self::UTILITY, $args, pm_ApiCli::RESULT_FULL, $env);
        } catch (pm_Exception $e) {
            throw new pm_Exception(self::mask($e->getMessage(), $password));
        }

        if ((int)$result['code'] !== 0) {
            $message = trim($result['stderr']) ?: trim($result['stdout']);
            if ($message === '') {
                $message = sprintf('%s exited with code %d', self::UTILITY, $result['code']);
            }
            throw new pm_Exception(self::mask($message, $password));
        }

        return (string)$result['stdout'];
    }

    private static function mask($message, $secret)
    {
        return $secret !== '' ? str_replace($secret, '********', $message) : $message;
    }
}
