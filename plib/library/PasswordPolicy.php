<?php

/**
 * The server's minimum password strength (Tools & Settings > Security Policy), as reported by
 * "plesk bin server_pref --show" (min_password_strength).
 *
 * Plesk's password meter rates passwords in five levels: VeryWeak, Weak, Medium, Strong, VeryStrong.
 * server_pref reports the highest policy as "Stronger", which Plesk's own error message calls "Very strong".
 */
class Modules_AdditionalAdministratorManager_PasswordPolicy
{
    /** server_pref policy value (lower case, without separators) => meter level. */
    const POLICY_LEVELS = [
        'veryweak' => 0,
        'weak' => 1,
        'medium' => 2,
        'strong' => 3,
        'stronger' => 4,
        'verystrong' => 4,
    ];

    /** @var string|false|null */
    private static $policy = false;

    /**
     * @return string|null The policy value as server_pref reports it (for example "Stronger"), or null if unknown.
     */
    public static function policy()
    {
        if (self::$policy === false) {
            self::$policy = null;
            try {
                $result = pm_ApiCli::call('server_pref', ['--show'], pm_ApiCli::RESULT_FULL);
                if ((int)$result['code'] === 0 && preg_match('/^min_password_strength:\s*(\S+)/m', $result['stdout'], $m)) {
                    self::$policy = $m[1];
                }
            } catch (pm_Exception $e) {
                pm_Log::err('Unable to read the minimum password strength: ' . $e->getMessage());
            }
        }
        return self::$policy;
    }

    /**
     * @return int|null Minimum meter level (0 = VeryWeak ... 4 = VeryStrong), or null if the policy is unknown.
     */
    public static function minimumLevel()
    {
        $key = strtolower(str_replace(['_', '-', ' '], '', (string)self::policy()));
        return isset(self::POLICY_LEVELS[$key]) ? self::POLICY_LEVELS[$key] : null;
    }
}
