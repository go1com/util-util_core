<?php

namespace go1\util\queue;

class QueueContext
{
    public const ACTION = [
        'user_create' => [
            self::ACTION_USER_CREATE_ADMIN_CREATE,
            self::ACTION_USER_CREATE_REGISTER,
            self::ACTION_USER_CREATE_EMBED_REGISTER,
            self::ACTION_USER_CREATE_INVITE,
            self::ACTION_USER_CREATE_IMPORT,
        ],
    ];

    public const ACTION_USER_CREATE_ADMIN_CREATE     = 'action.user_create.admin_create';  // context.is_new is required
    public const ACTION_USER_CREATE_REGISTER         = 'action.user_create.register';      // context.is_new is required
    public const ACTION_USER_CREATE_EMBED_REGISTER   = 'action.user_create.embed_register'; // context.is_new is required
    public const ACTION_USER_CREATE_INVITE           = 'action.user_create.invite';        // context.is_new is required
    public const ACTION_USER_CREATE_IMPORT           = 'action.user_create.import';        // context.is_new is required

    public const ACTION_ONBOARD_WELCOME              = 'action.onboard.welcome';

    public const ACTION_USER_MAIL_SECONDARY_CONFIRM  = 'action.user_mail_secondary.confirm';
    public const ACTION_USER_MAIL_SECONDARY_VERIFIED = 'action.user_mail_secondary.verified';

    public const ACTION_USER_EMAIL_CHANGED           = 'action.user.email_changed';

    public const ACTION_USER_PASSWORD_CHANGED        = 'action.user.password_changed';
    public const ACTION_USER_PASSWORD_FORGET         = 'action.user.password.forget';

    /** @deprecated */
    public const AWARD_NO_CONSUME_FLAG               = 'award.no-consume.flag'; // award stop consume flag
}
