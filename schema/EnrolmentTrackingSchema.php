<?php

namespace go1\util\schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

class EnrolmentTrackingSchema
{
    public static function install(Schema $schema): void
    {
        if (!$schema->hasTable('enrolment_tracking')) {
            $table = $schema->createTable('enrolment_tracking');
            $table->addColumn('enrolment_id', Type::INTEGER, ['unsigned' => true]);
            $table->addColumn('original_enrolment_type', Type::SMALLINT, ['unsigned' => true]);
            $table->addColumn('actor_id', Type::INTEGER, ['unsigned' => true]);
            $table->addColumn(
                'created_time',
                Types::DATETIME_MUTABLE,
                ['default' => 'CURRENT_TIMESTAMP']
            );
            $table->setPrimaryKey(['enrolment_id']);
            $table->addIndex(['actor_id']);
        }
    }
}
