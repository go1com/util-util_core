<?php

namespace go1\util\portal;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Connection;
use Exception;
use go1\clients\MqClient;
use go1\clients\UserClient;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\federation_api\v1\UserMapper;
use go1\domain_users\clients\portal\lib\Model\Portal;
use go1\util\collection\PortalCollectionConfiguration;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\queue\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use stdClass;
use function array_map;

class PortalHelper
{
    const LEGACY_VERSION = 'v2.11.0';
    const STABLE_VERSION = 'v3.0.0';

    const WEBSITE_DOMAIN             = 'www.go1.com';
    const WEBSITE_DEV_DOMAIN         = 'website.dev.go1.cloud';
    const WEBSITE_QA_DOMAIN          = 'website.qa.go1.cloud';
    const WEBSITE_PUBLIC_INSTANCE    = 'public.mygo1.com';
    const WEBSITE_STAGING_INSTANCE   = 'staging.mygo1.com';
    const WEBSITE_QA_INSTANCE        = 'qa.go1.cloud';
    const WEBSITE_DEV_INSTANCE       = 'dev.mygo1.com';
    const CUSTOM_DOMAIN_DEFAULT_HOST = 'go1portals.com';

    const LANGUAGE               = 'language';
    const LANGUAGE_DEFAULT       = 'en';
    const LOCALE                 = 'locale';
    const LOCALE_DEFAULT         = 'AU';
    const FEATURE_CREDIT         = 'credit';
    const FEATURE_CREDIT_DEFAULT = true;
    /** @deprecated */
    const FEATURE_SEND_WELCOME_EMAIL = 'send_welcome_email';
    /** @deprecated */
    const FEATURE_SEND_WELCOME_EMAIL_DEFAULT   = true;
    const FEATURE_CUSTOM_SMTP                  = 'custom_smtp';
    const FEATURE_CREDIT_REQUEST               = 'credit_request';
    const FEATURE_CREDIT_REQUEST_DEFAULT       = false;
    const FEATURE_NOTIFY_NEW_ENROLMENT         = 'notify_on_enrolment_create';
    const FEATURE_NOTIFY_NEW_ENROLMENT_DEFAULT = true;
    const FEATURE_NOTIFY_REMIND_MAJOR_EVENT    = 'notify_remind_major_event';
    const TIMEZONE_DEFAULT                     = "Australia/Brisbane";
    const COLLECTIONS                          = 'collections';
    const COLLECTIONS_DEFAULT                  = [
        PortalCollectionConfiguration::FREE,
        PortalCollectionConfiguration::PAID,
        PortalCollectionConfiguration::SUBSCRIBE,
        PortalCollectionConfiguration::SHARE,
    ];

    const PLAYER_APP_PREFIX  = 'play';
    const REACT_APP_PREFIX   = 'r';
    const DEFAULT_APP_PREFIX = 'p/#';
    const DEFAULT_WEB_APP    = 'webapp/#';

    private Client         $httpClient;
    private string         $portalServiceUrl;
    private ?CacheProvider $cacheProvider;
    private ?array         $retryPolicy;

    public function __construct(Client $httpClient, string $portalServiceUrl, CacheProvider $cacheProvider = null)
    {
        $this->httpClient = $httpClient;
        $this->portalServiceUrl = $portalServiceUrl;
        $this->cacheProvider = $cacheProvider;
    }

    public function load($nameOrId, $cache = true, $retryOnFailure = true): ?Portal
    {
        try {
            // Fetch portal from in-memory cache
            $portals = &DB::cache(__METHOD__, []);
            if ($cache && isset($portals[$nameOrId])) {
                return $portals[$nameOrId];
            }

            // Fetch portal from redis cache
            $cacheId = "portal_helper:portal:$nameOrId";
            if (!empty($this->cacheProvider) && $cache) {
                try {
                    $portal = $this->cache->fetch($cacheId);
                } catch (\Exception $e) {
                    // Ignore if cache server down
                }
            }

            $res = $this->httpClient->get("$this->portalServiceUrl/$nameOrId");
            $json = json_decode($res->getBody()->getContents(), true);
            if (empty($json)) {
                return null;
            }

            $portal = new Portal($json);
            // Add portal into in-memory
            $portals[$portal->id] = $portals[$portal->title] = $portal;

            // Add portal into redis cache
            try {
                if (!empty($this->cacheProvider)) {
                    $portal = $this->cache->saveMultiple([
                        "portal_helper:portal:$portal->id"    => $portal,
                        "portal_helper:portal:$portal->title" => $portal,
                    ]);
                }
            } catch (\Exception $e) {
                // Ignore if cache server down
            }

            return $portal;
        } catch (RequestException $e) {
            return null;
        } catch (ServerException $e) {
            if ($retryOnFailure) {
                return $this->load($nameOrId, false, false);
            }
            throw $e;
        }
    }

    /**
     * @deprecated
     * This method update the portal data therefore it should be used by portal service only
     *
     * @param Connection $db
     * @param MqClient $queue
     * @param string $version
     * @param $portalId
     * @return void|null
     * @throws \Doctrine\DBAL\Exception
     */
    public static function updateVersion(Connection $db, MqClient $queue, string $version, $portalId)
    {
        if (!$original = self::load($db, $portalId)) {
            return null;
        }

        $db->update('gc_instance', ['version' => $version], ['id' => $portalId]);
        $portal = self::load($db, $portalId);
        $portal->original = $original;
        $queue->publish($portal, Queue::PORTAL_UPDATE);
    }

    public function idFromName(string $portalName): ?int
    {
        $portal = $this->load($portalName);
        return $portal ? $portal->getId() : null;
    }

    public function nameFromId(int $id): ?string
    {
        $portal = $this->load($portalName);
        return $portal ? $portal->getTitle() : null;
    }

    /**
     * @deprecated It should be used by portal service only
     *
     * @param stdClass $portal
     * @return void
     */
    public static function parseConfig(stdClass &$portal)
    {
        if (!isset($portal->configuration)) {
            if (!empty($portal->data)) {
                $portal->data = is_scalar($portal->data) ? json_decode($portal->data) : $portal->data;
                if (!empty($portal->data->configuration)) {
                    $portal->configuration = $portal->data->configuration;
                    unset($portal->data->configuration);

                    if (isset($portal->configuration->dashboard_blocks) && is_scalar($portal->configuration->dashboard_blocks)) {
                        $portal->configuration->dashboard_blocks = json_decode($portal->configuration->dashboard_blocks);
                    }
                }
            }
        }

        if (!isset($portal->features)) {
            if (!empty($portal->data)) {
                $portal->data = is_scalar($portal->data) ? json_decode($portal->data) : $portal->data;
                if (!empty($portal->data->features)) {
                    $portal->features = $portal->data->features;
                    unset($portal->data->features);
                }
            }
        }
    }

    /**
     * @deprecated Portal and LO service is no longer shares the same database. Therefore it is likely impossible to joining two tables.
     *
     * @param Connection $db
     * @param int $loId
     * @return array|false|mixed
     * @throws \Doctrine\DBAL\Exception
     */
    public static function loadFromLoId(Connection $db, int $loId)
    {
        $portal = &DB::cache(__METHOD__, []);

        if (isset($portal[$loId])) {
            return $portal[$loId];
        }

        return $portal[$loId] = $db->executeQuery(
            'SELECT gc_instance.* FROM gc_instance'
            . ' INNER JOIN gc_lo ON gc_instance.id = gc_lo.instance_id'
            . ' WHERE gc_lo.id = ?',
            [$loId])->fetch(DB::OBJ);
    }

    /**
     * @deprecated Portal and LO service is no longer shares the same database. Therefore it is likely impossible to joining two tables.
     *
     * @param Connection $db
     * @param int $loId
     * @return false|mixed
     * @throws \Doctrine\DBAL\Exception
     */
    public static function titleFromLoId(Connection $db, int $loId)
    {
        return $db->executeQuery(
            'SELECT gc_instance.title FROM gc_instance'
            . ' INNER JOIN gc_lo ON gc_instance.id = gc_lo.instance_id'
            . ' WHERE gc_lo.id = ?',
            [$loId]
        )->fetchColumn();
    }

    public static function logo(Portal $portal): ?string
    {
        $conf = $portal->getConfiguration();
        $logo = $conf ? $conf->getLogo() : null;
        if (!$logo) {
            return $logo;
        }

        return (filter_var($logo, FILTER_VALIDATE_URL) === false) ? ('https:' . $logo) : $logo;
    }

    /**
     * @deprecated This method should be used by user service only. If you want to use it please reach out to IAM team and we can discuss more about this use case.
     *
     * @param Connection $db
     * @param string $portalName
     * @return array|false
     * @throws \Doctrine\DBAL\Exception
     */
    public static function roles(Connection $db, string $portalName)
    {
        $roles = $db->executeQuery('SELECT id, name FROM gc_role WHERE instance = ?', [$portalName])->fetchAll(DB::OBJ);

        return array_combine(array_column($roles, 'id'), array_column($roles, 'name'));
    }

    /**
     * Static method to get timezone of portal
     * portal predefined properties GET /portal/properties/timezones endpoint
     *
     * @param Portal    $portal
     * @param string|null $defaultTimezone
     * @return string
     */
    public static function timezone(Portal $portal, string $defaultTimezone = null): ?string
    {
        $conf = $portal->getConfiguration();
        $timezone = $conf ? $conf->getTimezone() : null;

        return $timezone ?? ($defaultTimezone ?: self::TIMEZONE_DEFAULT);
    }

    public static function portalAdminIds(UserClient $userClient, string $portalName): array
    {
        foreach ($userClient->findAdministrators($portalName, true) as $admin) {
            $adminIds[] = $admin->id;
        }

        return $adminIds ?? [];
    }

    public static function portalAdmins(UserClient $userClient, string $portalName): array
    {
        $adminIds = self::portalAdminIds($userClient, $portalName);
        $adminIds = array_map('intval', $adminIds);
        $admins = !$adminIds ? [] : array_map(
            function (User $user) { return UserMapper::toLegacyStandardFormat('', $user); },
            $userClient->helper()->loadMultipleUsers($adminIds)
        );

        return $admins;
    }

    public static function language(Portal $portal): ?string
    {
        $conf = $portal->getConfiguration();
        $language = $conf ? $conf->getLanguage() : null;

        return $language ?? self::LANGUAGE_DEFAULT;
    }

    public static function locale(Portal $portal): ?string
    {
        $conf = $portal->getConfiguration();
        $locale = $conf ? $conf->getLocale() : null;

        return $locale ?? self::LOCALE_DEFAULT;
    }

    public static function collections(Portal $portal): ?array
    {
        $conf = $portal->getConfiguration();
        $collections = $conf ? $conf->getCollections() : null;

        return $collections ?? self::COLLECTIONS_DEFAULT;
    }

    /**
     * @deprecated This method should be used by portal service only. Please use PortalHelper::load to get portal data.
     * @param Connection $db
     * @param int $portalId
     * @return array|false|mixed
     * @throws \Doctrine\DBAL\Exception
     */
    public static function loadPortalDataById(Connection $db, int $portalId)
    {
        return $db->executeQuery('SELECT * FROM portal_data WHERE id = ?', [$portalId])->fetch(DB::OBJ);
    }

    public static function getDomainDNSRecords($name): array
    {
        foreach (dns_get_record($name, DNS_A) as $mappingDomain => $mapping) {
            isset($mapping['ip']) && $ips[] = $mapping['ip'];
        }

        return $ips ?? [];
    }

    public static function validateCustomDomainDNS(string $domain): bool
    {
        $GO1Ips = self::getDomainDNSRecords(self::CUSTOM_DOMAIN_DEFAULT_HOST);
        $domainIps = self::getDomainDNSRecords($domain);
        $validated = array_intersect($GO1Ips, $domainIps);

        return sizeof($validated) > 0;
    }

    public static function isSSLEnabledDomain(string $domain): bool
    {
        try {
            $streamContext = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
            $read = @fopen("https://" . $domain, "rb", false, $streamContext);
            if (false !== $read) {
                $response = stream_context_get_params($read);

                return !!$response["options"]["ssl"]["peer_certificate"];
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function getWebsiteDomain(string $uri = ''): string
    {
        $env = getenv('ENV') ?: 'production';
        switch ($env) {
            case 'dev':
                return 'https://' . self::WEBSITE_DEV_DOMAIN . $uri;
            case 'staging':
            case 'qa':
                return 'https://' . self::WEBSITE_QA_DOMAIN . $uri;
            default:
                return 'https://' . self::WEBSITE_DOMAIN . $uri;
        }
    }
}
