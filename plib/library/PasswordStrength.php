<?php

/**
 * Rates passwords the way Plesk's own password meter does, for pages without a Plesk session.
 *
 * Uses Plesk's estimator (the one behind its meter). That class is internal to Plesk, so if a Plesk
 * update removes or changes it, this falls back to the zxcvbn library Plesk bundles, with Plesk's
 * minimum lengths per level (EstimationResult::getMinimumLengthByLevel(): 8, and 16 for VeryStrong).
 * The fallback gives the level only, without suggestions.
 */
class Modules_AdditionalAdministratorManager_PasswordStrength
{
    const PLESK_ESTIMATOR_FACTORY = 'Plesk\CommonPanel\Validate\Password\Estimator\EstimatorFactory';
    const ZXCVBN = 'ZxcvbnPhp\Zxcvbn';

    /** Minimum length per level (0 = VeryWeak ... 4 = VeryStrong), as Plesk applies them. */
    const MINIMUM_LENGTHS = [8, 8, 8, 8, 16];

    /**
     * @param string[] $userInputs Words the password should not be based on (username, name, email address)
     * @return array{level: int, suggestions: string[]}|null Level 0-4 and Plesk's suggestion keys, or null if no estimator works.
     */
    public static function estimate($password, array $userInputs = [])
    {
        $userInputs = array_values(array_filter(array_map('strval', $userInputs), 'strlen'));

        try {
            if (class_exists(self::PLESK_ESTIMATOR_FACTORY)) {
                $result = call_user_func([self::PLESK_ESTIMATOR_FACTORY, 'createEstimator'])->estimatePassword($password, $userInputs);
                return [
                    'level' => max(0, min(4, (int)$result->getLevel())),
                    'suggestions' => array_values(array_map('strval', (array)$result->getSuggestions())),
                ];
            }
        } catch (Throwable $e) {
            pm_Log::err('Plesk password estimator failed, using zxcvbn: ' . $e->getMessage());
        }

        try {
            if (class_exists(self::ZXCVBN)) {
                $class = self::ZXCVBN;
                $result = (new $class())->passwordStrength($password, $userInputs);
                $level = max(0, min(4, (int)$result['score']));
                $length = mb_strlen($password, 'UTF-8');
                while ($level > 0 && $length < self::MINIMUM_LENGTHS[$level]) {
                    $level--;
                }
                if ($length < self::MINIMUM_LENGTHS[0]) {
                    $level = 0;
                }
                return ['level' => $level, 'suggestions' => []];
            }
        } catch (Throwable $e) {
            pm_Log::err('zxcvbn password estimation failed: ' . $e->getMessage());
        }

        return null;
    }
}
