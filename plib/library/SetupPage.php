<?php

/**
 * Public (unauthenticated) page where an invited administrator chooses a username and password.
 * Served by htdocs/public/index.php; access requires a valid invitation token in ?auth=.
 *
 * The page is in the invited account's language (see Modules_AdditionalAdministratorManager_Locales),
 * not in the language of the visitor's browser or of Plesk's session.
 */
class Modules_AdditionalAdministratorManager_SetupPage
{
    const PASSWORD_MAX_LENGTH = 255;
    const LOGIN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @var string Interface language of the invited account (Plesk's default for an invalid link). */
    private $locale;

    public function run()
    {
        $this->sendSecurityHeaders();

        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method !== 'GET' && $method !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }

        $token = isset($_GET['auth']) && is_string($_GET['auth']) ? $_GET['auth'] : '';
        $login = Modules_AdditionalAdministratorManager_Invitations::findLoginByToken($token);
        $this->locale = $login === null
            ? Modules_AdditionalAdministratorManager_Locales::defaultLocale()
            : Modules_AdditionalAdministratorManager_Locales::ofAccount($login);
        if ($login === null) {
            http_response_code(404);
            $this->render(['invalid' => true]);
            return;
        }

        if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'estimate') {
            $this->estimate($login);
            return;
        }

        $username = $login;
        $errors = [];
        if ($method === 'POST') {
            $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $confirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

            $errors = $this->validate($login, $username, $password, $confirm);
            if (!$errors) {
                try {
                    if (!Modules_AdditionalAdministratorManager_Invitations::complete($token, $username, $password)) {
                        // Used or expired between loading and submitting the form.
                        http_response_code(404);
                        $this->render(['invalid' => true]);
                        return;
                    }
                    // Plesk's login page in the account's language.
                    header('Location: /login_up.php?locale_id=' . rawurlencode($this->locale), true, 303);
                    return;
                } catch (pm_Exception $e) {
                    $errors['password'] = $e->getMessage();
                }
            }
        }

        $this->render([
            'invalid' => false,
            'token' => $token,
            'username' => $username,
            'errors' => $errors,
            'passwordPolicy' => Modules_AdditionalAdministratorManager_PasswordPolicy::policy(),
            'minimumLevel' => Modules_AdditionalAdministratorManager_PasswordPolicy::minimumLevel(),
        ]);
    }

    /**
     * Answers the page's strength requests (POST action=estimate) with Plesk's rating of the password:
     * {"level": 0-4 or null, "suggestions": [Plesk suggestion keys]}. Requires the invitation token like the page itself.
     */
    private function estimate($login)
    {
        header('Content-Type: application/json; charset=UTF-8');

        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
        $result = null;
        if ($password !== '' && strlen($password) <= self::PASSWORD_MAX_LENGTH) {
            // Like Plesk's meter, rate passwords based on the account's own details lower.
            $info = Modules_AdditionalAdministratorManager_AdminAlias::info($login);
            $result = Modules_AdditionalAdministratorManager_PasswordStrength::estimate(
                $password,
                [$username, $login, $info['contact'], $info['email']]
            );
        }

        echo json_encode($result ?: ['level' => null, 'suggestions' => []]);
    }

    /**
     * @return array<string, string> Error messages keyed by field name.
     */
    private function validate($login, $username, $password, $confirm)
    {
        $errors = [];

        if ($username === '') {
            $errors['username'] = $this->lmsg('errorRequired');
        } elseif (strlen($username) > Modules_AdditionalAdministratorManager_AdminAlias::LOGIN_MAX_LENGTH) {
            $errors['username'] = $this->lmsg('errorLoginTooLong', ['max' => Modules_AdditionalAdministratorManager_AdminAlias::LOGIN_MAX_LENGTH]);
        } elseif (!preg_match(self::LOGIN_PATTERN, $username)) {
            $errors['username'] = $this->lmsg('errorLoginCharacters');
        } elseif (Modules_AdditionalAdministratorManager_AdminAlias::isReservedLogin($username)) {
            $errors['username'] = $this->lmsg('errorLoginReserved');
        } elseif ($username !== $login && strcasecmp($username, $login) !== 0
            && Modules_AdditionalAdministratorManager_AdminAlias::isLoginTaken($username)) {
            $errors['username'] = $this->lmsg('errorLoginTaken', ['login' => $username]);
        }

        if ($password === '') {
            $errors['password'] = $this->lmsg('errorRequired');
        } elseif (strlen($password) > self::PASSWORD_MAX_LENGTH) {
            $errors['password'] = $this->lmsg('errorPasswordTooLong', ['max' => self::PASSWORD_MAX_LENGTH]);
        } elseif (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = $this->lmsg('errorPasswordMismatch');
        }

        return $errors;
    }

    /**
     * Message in the language of the page (see Locales::lmsg()).
     */
    private function lmsg($key, array $params = [])
    {
        return Modules_AdditionalAdministratorManager_Locales::lmsg($this->locale, $key, $params);
    }

    private function sendSecurityHeaders()
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        // The token is in the URL: never leak it through the Referer header.
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function render(array $vars)
    {
        $e = function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $lmsg = function ($key, array $params = []) {
            return $this->lmsg($key, $params);
        };
        $t = function ($key, array $params = []) use ($e, $lmsg) {
            return $e($lmsg($key, $params));
        };
        // Language of the page's texts; Plesk's own texts on the page are loaded in the same language.
        $lang = Modules_AdditionalAdministratorManager_Locales::contentLocale($this->locale);
        $assets = '/modules/' . pm_Context::getModuleId() . '/public/';
        $logoUrl = Modules_AdditionalAdministratorManager_Branding::logoUrl();
        extract($vars);

        include pm_Context::getPlibDir() . 'views/scripts/public/setup.phtml';
    }
}
