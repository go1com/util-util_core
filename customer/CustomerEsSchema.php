<?php

namespace go1\util\customer;

use go1\util\es\Schema;

class CustomerEsSchema
{
    /**
     * @deprecated `customer` index on ES version 5.6
     */
    public const INDEX     = ES_INDEX . '_customer';

    /**
     * `customer` indices on ES version 8
     */
    public const INDEX_ES8_PORTAL  = ES_INDEX . '_customer_portal';
    public const INDEX_ES8_USER    = ES_INDEX . '_customer_user';
    public const INDEX_ES8_ACCOUNT = ES_INDEX . '_customer_account';

    public const O_PORTAL  = 'portal';
    public const O_USER    = 'user';
    public const O_ACCOUNT = 'account';

    /**
     * @deprecated `customer` index's mappings on ES version 5.6
     */
    public const MAPPING = [
        self::O_PORTAL  => self::PORTAL_MAPPING,
        self::O_USER    => self::USER_MAPPING,
        self::O_ACCOUNT => self::ACCOUNT_MAPPING,
    ];

    public const PORTAL_MAPPING = [
        '_routing'   => ['required' => true],
        'properties' => [
            'id'                => ['type' => Schema::T_KEYWORD],
            'title'             => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
            'status'            => ['type' => Schema::T_SHORT],
            'name'              => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED_AND_NORMALIZED,
            'version'           => ['type' => Schema::T_KEYWORD],
            'created'           => ['type' => Schema::T_DATE],
            'configuration'     => ['type' => Schema::T_OBJECT],
            'legacy'            => ['type' => Schema::T_INT],
            'logo'              => ['type' => Schema::T_TEXT],
            'score'             => ['type' => Schema::T_INT], # activity score
            'user_count'        => ['type' => Schema::T_INT],
            'active_user_count' => ['type' => Schema::T_INT], # last 30 days
            'plan'              => [
                'properties' => [
                    'name'     => ['type' => Schema::T_KEYWORD], # platform|premium
                    'status'   => ['type' => Schema::T_INT], # 0: Free, 1: Trial, 2: Paid, 3: Overdue invoice
                    'license'  => ['type' => Schema::T_INT],
                    'regional' => ['type' => Schema::T_KEYWORD],
                ],
            ],
            'csm'               => [
                'properties' => [
                    'user_id' => ['type' => Schema::T_INT],
                ],
            ],
        ],
    ];

    public const USER_MAPPING = [
        'properties' => [
            'id'                     => ['type' => Schema::T_KEYWORD],
            'profile_id'             => ['type' => Schema::T_INT],
            'mail'                   => ['type' => Schema::T_KEYWORD],
            'name'                   => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED_AND_NORMALIZED,
            'first_name'             => ['type' => Schema::T_KEYWORD],
            'last_name'              => ['type' => Schema::T_KEYWORD],
            'created'                => ['type' => Schema::T_DATE],
            'login'                  => ['type' => Schema::T_DATE],
            'access'                 => ['type' => Schema::T_DATE],
            'status'                 => ['type' => Schema::T_SHORT],
            'allow_public'           => ['type' => Schema::T_INT],
            'avatar'                 => ['type' => Schema::T_TEXT],
            'roles'                  => ['type' => Schema::T_KEYWORD],
            'timestamp'              => ['type' => Schema::T_DATE],
            'subscribed_product_ids' => ['type' => Schema::T_INT], # @see go1-core/content-subscription-index
        ],
    ];

    public const ACCOUNT_MAPPING = [
        '_routing'          => ['required' => true],
        'properties'        => [
            'id'           => ['type' => Schema::T_KEYWORD],
            'instance'     => ['type' => Schema::T_KEYWORD],
            'mail'         => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
            'name'         => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED_AND_NORMALIZED,
            'first_name'   => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
            'last_name'    => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
            'created'      => ['type' => Schema::T_DATE],
            'login'        => ['type' => Schema::T_DATE],
            'access'       => ['type' => Schema::T_DATE],
            'status'       => ['type' => Schema::T_SHORT],
            'allow_public' => ['type' => Schema::T_INT],
            'avatar'       => ['type' => Schema::T_TEXT],
            'roles'        => ['type' => Schema::T_KEYWORD],
            'groups'       => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
            'timestamp'    => ['type' => Schema::T_DATE],
            'managers'     => ['type' => Schema::T_INT], # Use user.id of manager
            'metadata'     => [
                'properties' => [
                    'user_id'     => ['type' => Schema::T_INT],
                    'instance_id' => ['type' => Schema::T_INT],
                    'updated_at'  => ['type' => Schema::T_INT],
                ],
            ],
            'learning'     => [
                'properties' => [
                    'assigned'       => ['type' => Schema::T_KEYWORD],
                    'not_started'    => ['type' => Schema::T_KEYWORD],
                    'in_progress'    => ['type' => Schema::T_KEYWORD],
                    'last_completed' => ['type' => Schema::T_KEYWORD],
                    'completed'      => ['type' => Schema::T_KEYWORD],
                    'expired'        => ['type' => Schema::T_KEYWORD],
                    'all'            => ['type' => Schema::T_KEYWORD],
                ],
            ],
        ],
        'dynamic_templates' => [
            [
                'custom_field_string' => [
                    'path_match' => 'fields_*.*.value_string',
                    'mapping'    => ['type' => Schema::T_KEYWORD] + Schema::ANALYZED,
                ],
            ],
            [
                'custom_field_text' => [
                    'path_match' => 'fields_*.*.value_text',
                    'mapping'    => ['type' => Schema::T_TEXT],
                ],
            ],
            [
                'custom_field_integer' => [
                    'path_match' => 'fields_*.*.value_integer',
                    'mapping'    => ['type' => Schema::T_INT],
                ],
            ],
            [
                'custom_field_float' => [
                    'path_match' => 'fields_*.*.value_float',
                    'mapping'    => ['type' => Schema::T_DOUBLE],
                ],
            ],
            [
                'custom_field_date' => [
                    'path_match' => 'fields_*.*.value_date',
                    'mapping'    => ['type' => Schema::T_DATE],
                ],
            ],
            [
                'custom_field_datetime' => [
                    'path_match' => 'fields_*.*.value_datetime',
                    'mapping'    => ['type' => Schema::T_DATE],
                ],
            ],
        ],
    ];

    public static function indexSchema(): array
    {
        return [
            'settings' => [
                    'number_of_shards'                 => getenv('ES_SCHEMA_NUMBER_OF_SHARDS') ?: 3,
                    'number_of_replicas'               => getenv('ES_SCHEMA_NUMBER_OF_REPLICAS') ?: 1,
                    'index.mapping.total_fields.limit' => getenv('ES_SCHEMA_LIMIT_TOTAL_FIELDS') ?: 5000,
                ] + Schema::SETTINGS,
            'mappings' => self::MAPPING,
        ];
    }

    public static function indexES8Schema(string $index): array
    {
        switch ($index) {
            case self::INDEX_ES8_PORTAL:
                $mapping = self::PORTAL_MAPPING;
                break;

            case self::INDEX_ES8_USER:
                $mapping = self::USER_MAPPING;
                break;

            case self::INDEX_ES8_ACCOUNT:
            default:
                $mapping = self::ACCOUNT_MAPPING;
                break;
        }

        return [
            'settings' => [
                'number_of_shards'                 => getenv('ES_SCHEMA_NUMBER_OF_SHARDS') ?: 3,
                'number_of_replicas'               => getenv('ES_SCHEMA_NUMBER_OF_REPLICAS') ?: 1,
                'index.mapping.total_fields.limit' => getenv('ES_SCHEMA_LIMIT_TOTAL_FIELDS') ?: 5000,
            ],
            'mappings' => $mapping,
        ];
    }
}
