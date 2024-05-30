<?php

namespace go1\util\collection;

class PortalCollectionConfiguration
{
    public const FREE         = 'free';
    public const PAID         = 'paid';
    public const SUBSCRIBE    = 'subscribe';
    public const CUSTOM       = 'custom';
    public const SHARE        = 'share';
    public const CUSTOM_SHARE = 'custom_share';
    public const CONTENT_LOADER = 'content_loader';
    public const UNADDED_TO_LIBRARY = 'unadded_to_library';

    public const ALL = [
        self::FREE,
        self::SUBSCRIBE,
        self::PAID,
        self::CUSTOM,
        self::SHARE,
        self::CUSTOM_SHARE,
        self::CONTENT_LOADER,
        self::UNADDED_TO_LIBRARY
    ];
}
