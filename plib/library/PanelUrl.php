<?php

/**
 * Base URL of the Plesk interface, as configured in Tools & Settings > Customize Plesk URL.
 *
 * Plesk keeps that setting in misc.access-domain-enabled / misc.access-domain-name:
 * - disabled: https://<FullHostName>:<panel port>/
 * - enabled, empty name ("all domains and subdomains that resolve to the server IP but are not
 *   used for hosting"): https://<FullHostName>/, unless the host name is itself a hosted domain
 * - enabled, with a name: https://<access-domain-name>/
 *
 * The panel port is not stored in Plesk's database; it is read from the sw-cp-server config,
 * which is where it is changed (https://support.plesk.com/hc/en-us/articles/12377561753111).
 */
class Modules_AdditionalAdministratorManager_PanelUrl
{
    const SW_CP_SERVER_CONF = '/etc/sw-cp-server/conf.d/plesk.conf';
    const DEFAULT_PORT = 8443;

    public static function base()
    {
        $hostname = self::hostname();
        try {
            $db = pm_Bootstrap::getDbAdapter();
            $misc = $db->fetchPairs(
                "SELECT param, val FROM misc WHERE param IN ('access-domain-enabled', 'access-domain-name')"
            );
            if (isset($misc['access-domain-enabled']) && $misc['access-domain-enabled'] === 'true') {
                $name = isset($misc['access-domain-name']) ? strtolower(trim($misc['access-domain-name'])) : '';
                if ($name !== '' && self::isHostname($name)) {
                    return 'https://' . $name . '/';
                }
                // On port 443 a hosted domain serves its own site, not Plesk.
                if ($name === '' && !$db->fetchOne("SELECT 1 FROM domains WHERE name = ?", [$hostname])) {
                    return 'https://' . $hostname . '/';
                }
            }
        } catch (Exception $e) {
            pm_Log::err('additional-administrator-manager: unable to read the Plesk access URL: ' . $e->getMessage());
        }
        return 'https://' . $hostname . ':' . self::port() . '/';
    }

    /**
     * The server host name Plesk uses (misc.FullHostName), falling back to the system host name.
     */
    public static function hostname()
    {
        try {
            $name = pm_Bootstrap::getDbAdapter()->fetchOne("SELECT val FROM misc WHERE param = 'FullHostName'");
        } catch (Exception $e) {
            $name = null;
        }
        $name = is_string($name) ? strtolower(trim($name)) : '';
        return self::isHostname($name) ? $name : strtolower((string)gethostname());
    }

    /**
     * The HTTPS port of the panel: the first "listen [addr:]port ... ssl;" in the sw-cp-server config.
     */
    private static function port()
    {
        $conf = @file_get_contents(self::SW_CP_SERVER_CONF);
        if (is_string($conf) && preg_match('/^\s*listen\s+(?:\S+:)?(\d{1,5})\b[^;]*\bssl\b[^;]*;/m', $conf, $m)) {
            $port = (int)$m[1];
            if ($port > 0 && $port <= 65535) {
                return $port;
            }
        }
        return self::DEFAULT_PORT;
    }

    private static function isHostname($name)
    {
        return (bool)preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $name);
    }
}
