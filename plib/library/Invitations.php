<?php

/**
 * Invitations for new additional administrators.
 *
 * Inviting creates a disabled account with a derived username and a random temporary
 * password nobody sees. The invitee opens the setup link, picks a username and password,
 * and the account is enabled.
 *
 * Invitations are rows of the "invitations" table in the extension's SQLite database
 * (see Modules_AdditionalAdministratorManager_Db). Only a SHA-256 hash of each access token is stored.
 *
 * An invitation belongs to one account, not just to a username: it is only valid while an account
 * with the stored username (or, after a rename, the stored email address) exists, is still disabled
 * and still has the email address the invitation was sent to. Invitations that no longer match are
 * deleted as orphans when they are checked. Plesk events (library/EventListener.php) keep the table
 * current in between, but validity never depends on them.
 */
class Modules_AdditionalAdministratorManager_Invitations
{
    const TOKEN_LIFETIME = 604800; // One week.
    const NEUTRAL_LOGIN = 'invitee';

    /**
     * Seconds a new invitation may exist before its account does: the row is stored first,
     * so the delayed admin_alias_create event can attach the account ID to it.
     */
    const CREATION_GRACE = 60;

    /**
     * Creates the disabled account and emails the invitation.
     *
     * @param string $locale Interface language of the account, also used for the invitation (see Locales).
     * @return string The generated username.
     */
    public static function invite($email, $contact, $comment, $locale)
    {
        if (Modules_AdditionalAdministratorManager_AdminAlias::isPrimaryAdminEmail($email)) {
            throw new pm_Exception(pm_Locale::lmsg('errorEmailPrimaryAdmin', ['email' => $email]));
        }
        if (Modules_AdditionalAdministratorManager_AdminAlias::findLoginByEmail($email) !== null) {
            throw new pm_Exception(pm_Locale::lmsg('errorEmailInUse', ['email' => $email]));
        }

        $login = self::availableLogin(self::loginFromEmail($email));
        $expires = time() + self::TOKEN_LIFETIME;
        $token = self::newToken();

        // Replaces any orphaned invitation that still uses this username.
        Modules_AdditionalAdministratorManager_Db::adapter()->query(
            'INSERT OR REPLACE INTO invitations (login, token_hash, expires_at, created_at, alias_id, email) VALUES (?, ?, ?, ?, NULL, ?)',
            [$login, hash('sha256', $token), $expires, time(), strtolower($email)]
        );
        try {
            Modules_AdditionalAdministratorManager_AdminAlias::create(
                $login, self::temporaryPassword(), $email, $contact, $comment, $locale, false
            );
        } catch (Exception $e) {
            self::forget($login);
            throw $e;
        }

        try {
            self::mail($login, $token, $expires);
        } catch (pm_Exception $e) {
            throw new pm_Exception(pm_Locale::lmsg('errorInviteNotSent', ['login' => $login, 'error' => $e->getMessage()]));
        }

        Modules_AdditionalAdministratorManager_Log::submit(Modules_AdditionalAdministratorManager_Log::INVITATION_SENT, [], [
            'logFieldLogin' => $login,
            'logFieldContact' => $contact,
            'logFieldLanguage' => $locale,
            'logFieldExpires' => self::formatDate($expires),
        ]);

        return $login;
    }

    /**
     * Issues a new token, which also invalidates the previous one and restarts the one-week validity.
     */
    public static function resend($login)
    {
        $invitation = self::find($login);
        if ($invitation === null) {
            throw new pm_Exception(pm_Locale::lmsg('errorNoInvitation', ['login' => $login]));
        }

        $expires = time() + self::TOKEN_LIFETIME;
        $token = self::newToken();
        Modules_AdditionalAdministratorManager_Db::adapter()->query(
            'UPDATE invitations SET token_hash = ?, expires_at = ? WHERE token_hash = ?',
            [hash('sha256', $token), $expires, $invitation['token_hash']]
        );
        self::mail($invitation['login'], $token, $expires);

        Modules_AdditionalAdministratorManager_Log::submit(Modules_AdditionalAdministratorManager_Log::INVITATION_RESENT, [], [
            'logFieldLogin' => $invitation['login'],
            'logFieldExpires' => self::formatDate($expires),
        ]);
    }

    /**
     * @param array<string, array>|null $accounts All accounts (login => AdminAlias::info()), if already loaded
     */
    public static function isPending($login, array $accounts = null)
    {
        return self::find($login, $accounts) !== null;
    }

    /**
     * @param array<string, array>|null $accounts All accounts (login => AdminAlias::info()), if already loaded
     * @return int|null Expiry time of the login's invitation, or null if it has none.
     */
    public static function expiresAt($login, array $accounts = null)
    {
        $invitation = self::find($login, $accounts);
        return $invitation === null ? null : (int)$invitation['expires_at'];
    }

    /**
     * @param array<string, array>|null $accounts All accounts (login => AdminAlias::info()), if already loaded
     */
    public static function isExpired($login, array $accounts = null)
    {
        $expires = self::expiresAt($login, $accounts);
        return $expires !== null && $expires < time();
    }

    /**
     * @return string|null Login of the valid, unexpired invitation the token belongs to.
     */
    public static function findLoginByToken($token)
    {
        $hash = self::hashToken($token);
        if ($hash === null) {
            return null;
        }
        $row = Modules_AdditionalAdministratorManager_Db::adapter()->fetchRow(
            'SELECT * FROM invitations WHERE token_hash = ? AND expires_at >= ?',
            [$hash, time()]
        );
        $invitation = $row ? self::verify($row) : null;
        return $invitation === null ? null : $invitation['login'];
    }

    /**
     * Applies the invitee's username and password, enables the account and destroys the token.
     *
     * The invitation is deleted in a transaction before the account is changed, so a token can
     * only be used by one request at a time. If the account change fails, the invitation is restored.
     *
     * @return bool False if the token is no longer valid.
     */
    public static function complete($token, $newLogin, $password)
    {
        $claimed = self::claim($token);
        $invitation = $claimed === null ? null : self::verify($claimed, null, false);
        if ($invitation === null) {
            return false;
        }

        try {
            Modules_AdditionalAdministratorManager_AdminAlias::completeInvitation($invitation['login'], $newLogin, $password);
        } catch (Exception $e) {
            Modules_AdditionalAdministratorManager_Db::adapter()->query(
                'INSERT OR IGNORE INTO invitations (login, token_hash, expires_at, created_at, alias_id, email) VALUES (?, ?, ?, ?, ?, ?)',
                [$invitation['login'], $invitation['token_hash'], $invitation['expires_at'], $invitation['created_at'],
                    $invitation['alias_id'], $invitation['email']]
            );
            throw $e;
        }

        // Plesk attributes actions on this session-less page to the main administrator,
        // so the invitee's usernames and IP address are recorded in the entry itself.
        Modules_AdditionalAdministratorManager_Log::submit(Modules_AdditionalAdministratorManager_Log::INVITATION_ACCEPTED, [
            'logFieldLogin' => $invitation['login'],
        ], [
            'logFieldLogin' => $newLogin,
            'logFieldIp' => Modules_AdditionalAdministratorManager_Log::clientIp(),
        ]);
        return true;
    }

    /**
     * Keeps a pending invitation when its email address is changed on the edit page. The link sent to
     * the previous address stops working; a resend mails a new link to the new address.
     */
    public static function changeEmail($login, $email)
    {
        Modules_AdditionalAdministratorManager_Db::adapter()->query(
            'UPDATE invitations SET email = ?, token_hash = ? WHERE login = ?',
            [strtolower($email), hash('sha256', self::newToken()), $login]
        );
    }

    /**
     * Deletes the invitation (and its token) of a login, if any.
     */
    public static function forget($login)
    {
        Modules_AdditionalAdministratorManager_Db::adapter()->delete('invitations', ['login = ?' => $login]);
    }

    /**
     * Checks every invitation against the current accounts, deleting orphans and following renames.
     *
     * @param array<string, array> $accounts All accounts (login => AdminAlias::info())
     */
    public static function sweep(array $accounts)
    {
        foreach (Modules_AdditionalAdministratorManager_Db::adapter()->fetchAll('SELECT * FROM invitations') as $row) {
            self::verify($row, $accounts);
        }
    }

    /**
     * Date format of invitation expiry times in emails, the Action Log and the edit page.
     */
    public static function formatDate($timestamp)
    {
        return date('Y-m-d H:i T', $timestamp);
    }

    /**
     * admin_alias_create event: attaches the new account's ID to its invitation.
     */
    public static function onAccountCreated($aliasId, $login)
    {
        Modules_AdditionalAdministratorManager_Db::adapter()->query(
            'UPDATE invitations SET alias_id = ? WHERE login = ? AND alias_id IS NULL',
            [$aliasId, $login]
        );
    }

    /**
     * admin_alias_update event. Enabling an account voids its invitation. The username is followed only
     * when it names an existing account: Plesk reports a rename with the old username in both the old
     * and new values, so the new name only arrives with the account's next event.
     */
    public static function onAccountUpdated($aliasId, $login, $enabled)
    {
        $db = Modules_AdditionalAdministratorManager_Db::adapter();
        $row = $db->fetchRow('SELECT * FROM invitations WHERE alias_id = ?', [$aliasId]);
        if (!$row) {
            return;
        }
        if ($enabled) {
            $db->delete('invitations', ['token_hash = ?' => $row['token_hash']]);
        } elseif (strcasecmp($login, $row['login']) !== 0 && Modules_AdditionalAdministratorManager_AdminAlias::exists($login)) {
            self::setLogin($row, $login);
        }
    }

    /**
     * admin_alias_delete event: deletes the account's invitation.
     */
    public static function onAccountDeleted($aliasId, $login)
    {
        Modules_AdditionalAdministratorManager_Db::adapter()->query(
            'DELETE FROM invitations WHERE alias_id = ? OR (alias_id IS NULL AND login = ?)',
            [$aliasId, $login]
        );
    }

    /**
     * @param array<string, array>|null $accounts All accounts (login => AdminAlias::info()), if already loaded
     * @return array|null The login's valid invitation row.
     */
    private static function find($login, array $accounts = null)
    {
        $row = Modules_AdditionalAdministratorManager_Db::adapter()->fetchRow(
            'SELECT * FROM invitations WHERE login = ?',
            [$login]
        );
        return $row ? self::verify($row, $accounts) : null;
    }

    /**
     * Returns the invitation if it still belongs to a disabled account with the invited email address,
     * with its login updated if the account was renamed. Otherwise deletes it and returns null.
     *
     * @param array<string, array>|null $accounts All accounts (login => AdminAlias::info()), or null to look them up
     * @param bool $stored False for a row that was already removed from the table (see claim())
     */
    private static function verify(array $row, array $accounts = null, $stored = true)
    {
        $account = self::accountByLogin($row['login'], $accounts);

        if ($account === null || strcasecmp(trim($account['email']), $row['email']) !== 0) {
            // Renamed outside the extension, or the username now belongs to a different account.
            $account = self::accountByEmail($row['email'], $accounts);
        }

        if ($account === null || $account['enabled']) {
            $justInvited = $account === null && (int)$row['created_at'] > time() - self::CREATION_GRACE;
            if ($stored && !$justInvited) {
                Modules_AdditionalAdministratorManager_Db::adapter()->delete('invitations', ['token_hash = ?' => $row['token_hash']]);
            }
            return null;
        }

        if ($account['login'] !== $row['login']) {
            if ($stored) {
                self::setLogin($row, $account['login']);
            }
            $row['login'] = $account['login'];
        }
        return $row;
    }

    private static function setLogin(array $row, $login)
    {
        $db = Modules_AdditionalAdministratorManager_Db::adapter();
        // An orphan may still hold the username (the primary key).
        $db->query('DELETE FROM invitations WHERE login = ? AND token_hash <> ?', [$login, $row['token_hash']]);
        $db->query('UPDATE invitations SET login = ? WHERE token_hash = ?', [$login, $row['token_hash']]);
    }

    private static function accountByLogin($login, array $accounts = null)
    {
        if ($accounts !== null) {
            foreach ($accounts as $account) {
                if (strcasecmp($account['login'], $login) === 0) {
                    return $account;
                }
            }
            return null;
        }
        try {
            return Modules_AdditionalAdministratorManager_AdminAlias::info($login);
        } catch (pm_Exception $e) {
            return null;
        }
    }

    /**
     * Prefers a disabled account when several share the address (possible for accounts made outside the extension).
     */
    private static function accountByEmail($email, array $accounts = null)
    {
        if ($email === '') {
            return null;
        }
        if ($accounts === null) {
            $accounts = Modules_AdditionalAdministratorManager_AdminAlias::all();
        }
        $found = null;
        foreach ($accounts as $account) {
            if (strcasecmp(trim($account['email']), $email) === 0 && ($found === null || $found['enabled'])) {
                $found = $account;
            }
        }
        return $found;
    }

    /**
     * Atomically removes and returns the unexpired invitation row of a token.
     *
     * @return array|null
     */
    private static function claim($token)
    {
        $hash = self::hashToken($token);
        if ($hash === null) {
            return null;
        }

        // BEGIN IMMEDIATE takes SQLite's write lock before the SELECT, so two requests cannot both
        // read the row. Zend's beginTransaction() only issues a deferred BEGIN, hence plain PDO.
        $connection = Modules_AdditionalAdministratorManager_Db::adapter()->getConnection();
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $select = $connection->prepare('SELECT * FROM invitations WHERE token_hash = ? AND expires_at >= ?');
            $select->execute([$hash, time()]);
            $invitation = $select->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($invitation !== null) {
                $connection->prepare('DELETE FROM invitations WHERE token_hash = ?')->execute([$hash]);
            }
            $connection->exec('COMMIT');
        } catch (Exception $e) {
            $connection->exec('ROLLBACK');
            throw $e;
        }
        return $invitation;
    }

    /**
     * @return string|null SHA-256 of a well-formed token, or null for anything else.
     */
    private static function hashToken($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return hash('sha256', $token);
    }

    /**
     * Local part of the address, reduced to characters allowed in usernames and cut to 20 characters.
     * A local part that leaves nothing usable, or matches a reserved username, becomes a neutral name.
     */
    private static function loginFromEmail($email)
    {
        $localPart = substr($email, 0, (int)strrpos($email, '@'));
        $login = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $localPart));
        $login = ltrim($login, '._-');
        $login = substr($login, 0, Modules_AdditionalAdministratorManager_AdminAlias::LOGIN_MAX_LENGTH);
        if ($login === '' || Modules_AdditionalAdministratorManager_AdminAlias::isReservedLogin($login)) {
            $login = self::NEUTRAL_LOGIN;
        }
        return $login;
    }

    /**
     * Appends 1, 2, 3, ... to $base (shortening it to stay within 20 characters) until the login is free.
     */
    private static function availableLogin($base)
    {
        $max = Modules_AdditionalAdministratorManager_AdminAlias::LOGIN_MAX_LENGTH;
        $candidate = $base;
        for ($i = 1; Modules_AdditionalAdministratorManager_AdminAlias::isLoginTaken($candidate); $i++) {
            if ($i > 999) {
                throw new pm_Exception(pm_Locale::lmsg('errorNoFreeLogin', ['login' => $base]));
            }
            $candidate = substr($base, 0, $max - strlen((string)$i)) . $i;
        }
        return $candidate;
    }

    /**
     * 64 random characters that always include upper case, lower case, digits and symbols.
     */
    private static function temporaryPassword()
    {
        $classes = ['ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz', '0123456789', '!@#$%^&*?_~'];
        $all = implode('', $classes);
        $chars = [];
        foreach ($classes as $class) {
            $chars[] = $class[random_int(0, strlen($class) - 1)];
        }
        while (count($chars) < 64) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            list($chars[$i], $chars[$j]) = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /**
     * @return string A new plain token; only its hash is stored.
     */
    private static function newToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Emails the invitation with the setup link for $token, in the account's language (see Locales).
     */
    private static function mail($login, $token, $expires)
    {
        $info = Modules_AdditionalAdministratorManager_AdminAlias::info($login);
        $locale = Modules_AdditionalAdministratorManager_Locales::ofAccount($login);

        $hostname = Modules_AdditionalAdministratorManager_Mailer::hostname();
        $template = @file_get_contents(Modules_AdditionalAdministratorManager_Locales::templatePath($locale));
        if ($template === false) {
            throw new pm_Exception(pm_Locale::lmsg('errorTemplateMissing'));
        }
        $body = strtr($template, [
            '{contact_name}' => Modules_AdditionalAdministratorManager_Mailer::singleLine($info['contact']),
            '{username}' => $login,
            '{hostname}' => $hostname,
            '{setup_url}' => self::setupUrl($token),
            '{expires}' => self::formatDate($expires),
        ]);

        Modules_AdditionalAdministratorManager_Mailer::send(
            $info['email'],
            Modules_AdditionalAdministratorManager_Locales::lmsg($locale, 'inviteEmailSubject', ['hostname' => $hostname]),
            $body
        );
    }

    /**
     * Link to the setup page (htdocs/public/index.php) for $token.
     */
    private static function setupUrl($token)
    {
        return Modules_AdditionalAdministratorManager_PanelUrl::base() . 'modules/'
            . pm_Context::getModuleId() . '/public/?auth=' . $token;
    }
}
