<?php

namespace go1\util;

use go1\util\k8s\K8SBridgeHelper;

use function defined;
use function getenv;

class Service
{
    public const VERSION = 'v19.02.22.0';

    public static function cacheOptions($root)
    {
        return (getenv('CACHE_BACKEND') && 'memcached' === getenv('CACHE_BACKEND'))
            ? ['backend' => 'memcached', 'host' => getenv('CACHE_HOST'), 'port' => getenv('CACHE_PORT')]
            : ['backend' => 'filesystem', 'directory' => $root . '/cache'];
    }

    public static function queueOptions()
    {
        return [
            'host' => getenv('QUEUE_HOST') ?: '172.31.11.129',
            'port' => getenv('QUEUE_PORT') ?: '5672',
            'user' => getenv('QUEUE_USER') ?: 'go1',
            'pass' => getenv('QUEUE_PASSWORD') ?: 'go1',
        ];
    }

    public static function accountsName(string $env): string
    {
        if ($configured = getenv('ACCOUNTS_NAME')) {
            return $configured;
        }

        switch ($env) {
            case 'production':
            case 'staging':
                return 'accounts.gocatalyze.com';

            default:
                return 'accounts-dev.gocatalyze.com';
        }
    }

    public static function urls(array $names, string $env, string $pattern = null): array
    {
        foreach ($names as $name) {
            $urls["{$name}_url"] = static::url($name, $env, $pattern);
        }

        return !empty($urls) ? $urls : [];
    }

    public static function url(string $name, string $env, string $pattern = null): string
    {
        if (getenv('AZURE_BRIDGE_MODE')) {
            // must be grpc prefixed service -> don't use proxy
            if (strpos($name, 'grpc-') === 0) {
                $name = substr($name, 5);
                [$k8sHost, $k8sPort] = K8SBridgeHelper::getServiceEnvValues($name, 'k8s-qa', 'grpc');
                if ($k8sHost && $k8sPort) {
                    return "{$k8sHost}:{$k8sPort}";
                }
            }

            // K8S Service discovery using ENV variables
            $proxyService = 'bridge-to-k8s-proxy';
            [$k8sHost, $k8sPort] = K8SBridgeHelper::getServiceEnvValues($proxyService);
            if ($k8sHost && $k8sPort) {
                return "http://{$k8sHost}:{$k8sPort}/{$name}";
            }
        }

        if (strpos($name, 'grpc-') === 0) {
            $name = substr($name, 5);
            $pattern = $pattern
                ? str_replace('http://', '', $pattern) . ':5000'
                : 'SERVICE.ENVIRONMENT:5000';
        }

        $pattern = $pattern ?? 'http://SERVICE.ENVIRONMENT';

        // There are some services don't have staging instance yet.
        if (in_array($name, ['rules'])) {
            $env = 'production';
        }

        return str_replace(['SERVICE', 'ENVIRONMENT'], [$name, $env], $pattern);
    }

    /**
     * This method is only for dev environment for now.
     *
     * The container's /etc/resolver.conf, change nameserver to
     *
     *  nameserver 172.31.10.148
     *
     * @param string $env
     * @param string $name
     * @return string[]
     */
    public static function ipPort(string $env, string $name)
    {
        $records = dns_get_record("$env.$name.service.consul", DNS_SRV);
        if ($records) {
            $service = &$records[0];

            return [$service['target'], $service['port']];
        }
    }

    public static function elasticSearchIndex(): string
    {
        !defined('ES_INDEX') && define('ES_INDEX', getenv('ES_INDEX') ?: 'go1_dev');

        return ES_INDEX;
    }

    public static function isLocalIp(): bool
    {
        $localIps = getenv('LOCAL_IPS');
        if ($localIps) {
            $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
            if ($ip) {
                $localIps = explode(',', $localIps);
                foreach ($localIps as $localIp) {
                    if (false !== strpos($ip, $localIp)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param string $env
     * @param bool $public
     * @return string
     */
    public static function gatewayUrl(string $env, $public = false)
    {
        if (!empty($url = getenv('GATEWAY_URL'))) {
            return $url;
        }

        if ($public) {
            $suffix = 'production' === $env ? '' : "-{$env}";

            return "https://api{$suffix}.go1.co";
        }

        return self::url('gateway', $env);
    }
}
