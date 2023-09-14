<?php

namespace go1\util\plan;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use go1\clients\MqClient;
use go1\util\DB;
use go1\util\plan\event_publishing\PlanCreateEventEmbedder;
use go1\util\plan\event_publishing\PlanDeleteEventEmbedder;
use go1\util\plan\event_publishing\PlanUpdateEventEmbedder;
use go1\util\queue\Queue;
use Ramsey\Uuid\Uuid;
use stdClass;

class PlanRepository implements DeferredMessagesInterface
{
    private const DATE_MYSQL = 'Y-m-d H:i:s';
    private Connection              $db;
    private MqClient                $queue;
    private PlanCreateEventEmbedder $planCreateEventEmbedder;
    private PlanUpdateEventEmbedder $planUpdateEventEmbedder;
    private PlanDeleteEventEmbedder $planDeleteEventEmbedder;
    private array $deferredMessages = [];

    public function __construct(
        Connection $db,
        MqClient $queue,
        PlanCreateEventEmbedder $planCreateEventEmbedder,
        PlanUpdateEventEmbedder $planUpdateEventEmbedder,
        PlanDeleteEventEmbedder $planDeleteEventEmbedder
    ) {
        $this->db = $db;
        $this->queue = $queue;
        $this->planCreateEventEmbedder = $planCreateEventEmbedder;
        $this->planUpdateEventEmbedder = $planUpdateEventEmbedder;
        $this->planDeleteEventEmbedder = $planDeleteEventEmbedder;
    }

    public static function install(Schema $schema): void
    {
        if (!$schema->hasTable('gc_plan')) {
            $plan = $schema->createTable('gc_plan');
            $plan->addOption('description', 'GO1P-10732: Store learn-planning object.');
            $plan->addColumn('id', Type::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $plan->addColumn('type', Type::SMALLINT, ['default' => PlanTypes::ASSIGN]);
            $plan->addColumn('user_id', Type::INTEGER, ['unsigned' => true]);
            $plan->addColumn('assigner_id', Type::INTEGER, ['unsigned' => true, 'notnull' => false]);
            $plan->addColumn('instance_id', Type::INTEGER, ['unsigned' => true, 'notnull' => false]);
            $plan->addColumn('entity_type', Type::STRING);
            $plan->addColumn('entity_id', Type::INTEGER, ['unsigned' => true]);
            $plan->addColumn('status', Type::INTEGER);
            $plan->addColumn('created_date', Type::DATETIME);
            $plan->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false, 'default' => 'CURRENT_TIMESTAMP']);
            $plan->addColumn('due_date', Type::DATETIME, ['notnull' => false]);
            $plan->addColumn('data', 'blob', ['notnull' => false]);
            $plan->setPrimaryKey(['id']);
            $plan->addIndex(['type']);
            $plan->addIndex(['user_id']);
            $plan->addIndex(['assigner_id']);
            $plan->addIndex(['instance_id']);
            $plan->addIndex(['entity_type', 'entity_id']);
            $plan->addIndex(['status']);
            $plan->addIndex(['created_date']);
            $plan->addIndex(['due_date']);
        }

        if (!$schema->hasTable('gc_plan_revision')) {
            $planRevision = $schema->createTable('gc_plan_revision');
            $planRevision->addColumn('id', Type::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $planRevision->addColumn('type', Type::SMALLINT);
            $planRevision->addColumn('plan_id', Type::INTEGER, ['unsigned' => true]);
            $planRevision->addColumn('user_id', Type::INTEGER, ['unsigned' => true]);
            $planRevision->addColumn('assigner_id', Type::INTEGER, ['unsigned' => true, 'notnull' => false]);
            $planRevision->addColumn('instance_id', Type::INTEGER, ['unsigned' => true, 'notnull' => false]);
            $planRevision->addColumn('entity_type', Type::STRING);
            $planRevision->addColumn('entity_id', Type::INTEGER, ['unsigned' => true]);
            $planRevision->addColumn('status', Type::INTEGER);
            $planRevision->addColumn('created_date', Type::DATETIME);
            $planRevision->addColumn('due_date', Type::DATETIME, ['notnull' => false]);
            $planRevision->addColumn('data', Type::BLOB, ['notnull' => false]);
            $planRevision->setPrimaryKey(['id']);
            $planRevision->addIndex(['type']);
            $planRevision->addIndex(['plan_id']);
            $planRevision->addIndex(['user_id']);
            $planRevision->addIndex(['assigner_id']);
            $planRevision->addIndex(['instance_id']);
            $planRevision->addIndex(['entity_type', 'entity_id']);
            $planRevision->addIndex(['status']);
            $planRevision->addIndex(['created_date']);
            $planRevision->addIndex(['due_date']);
        }
    }

    public function load(int $id)
    {
        $plan = $this
            ->db
            ->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$id])
            ->fetch(DB::OBJ);

        return $plan ? Plan::create($plan) : false;
    }

    /**
     * @param int[] $ids
     * @return Plan[]
     */
    public function loadMultiple(array $ids): array
    {
        $q = $this->db->createQueryBuilder();
        $q = $q
            ->select('*')
            ->from('gc_plan')
            ->where($q->expr()->in('id', ':ids'))
            ->setParameter(':ids', $ids, Connection::PARAM_INT_ARRAY)
            ->execute();

        $plans = [];
        while ($plan = $q->fetch(DB::OBJ)) {
            $plans[] = Plan::create($plan);
        }

        return $plans;
    }

    /**
     * @return Plan[]
     */
    public function loadByEntity(string $entityType, int $entityId, int $status = null, int $type = PlanTypes::ASSIGN): array
    {
        $q = $this->db->createQueryBuilder();
        $q
            ->select('*')
            ->from('gc_plan')
            ->where($q->expr()->eq('entity_type', ':entityType'))
            ->andWhere($q->expr()->eq('entity_id', ':entityId'))
            ->andWhere($q->expr()->eq('type', ':type'));

        if (!is_null($status)) {
            $q
                ->andWhere($q->expr()->eq('status', ':status'));
        }

        $q = $q->setParameters([
            ':entityType' => $entityType,
            ':entityId'   => $entityId,
            ':type'       => $type,
            ':status'     => $status,
        ])->execute();

        $plans = [];
        while ($plan = $q->fetch(DB::OBJ)) {
            $plans[] = Plan::create($plan);
        }

        return $plans;
    }

    /**
     * @return stdClass[]
     */
    public function loadRevisions(int $planId): array
    {
        $q = $this->db
            ->createQueryBuilder()
            ->select('*')
            ->from('gc_plan_revision')
            ->where('plan_id = :planId')
            ->setParameter(':planId', $planId)
            ->execute();

        $revisions = [];
        while ($revision = $q->fetch(DB::OBJ)) {
            $revision->data = $revision->data ? json_decode($revision->data) : $revision->data;
            $revisions[] = $revision;
        }

        return $revisions;
    }

    public function create(Plan &$plan, bool $apiUpliftV3 = false, bool $notify = false, array $queueContext = [], array $embedded = [], bool $isBatch = false): int
    {
        $plan->created = $plan->created ?? new DateTime();
        $this->db->insert('gc_plan', [
            'type'         => $plan->type,
            'user_id'      => $plan->userId,
            'assigner_id'  => $plan->assignerId,
            'instance_id'  => $plan->instanceId,
            'entity_type'  => $plan->entityType,
            'entity_id'    => $plan->entityId,
            'status'       => $plan->status,
            'created_date' => $plan->created->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_MYSQL),
            'due_date'     => $plan->due ? $plan->due->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_MYSQL) : null,
            'data'         => $plan->data ? json_encode($plan->data) : null,
        ]);

        $plan->id = (int) $this->db->lastInsertId('gc_plan');
        $plan->notify = $notify ?: ($queueContext['notify'] ?? false);

        $queueContext['notify'] = $plan->notify;
        $queueContext['sessionId'] = Uuid::uuid4()->toString();
        $payload = $plan->jsonSerialize();
        $payload['embedded'] = $embedded + $this->planCreateEventEmbedder->embedded($plan);

        if (!$apiUpliftV3) {
            if ($isBatch) {
                $this->queue->batchAdd($payload, Queue::PLAN_CREATE, $queueContext);
            } else {
                $this->queue->publish($payload, Queue::PLAN_CREATE, $queueContext);
            }
        } else {
            $this->deferredMessages[] = [
                'payload' => $payload,
                'routing_key' => Queue::PLAN_CREATE,
                'context' => $queueContext,
            ];
        }

        return $plan->id;
    }

    public function createRevision(Plan &$plan): void
    {
        $this->db->insert('gc_plan_revision', [
            'plan_id'      => $plan->id,
            'type'         => $plan->type,
            'user_id'      => $plan->userId,
            'assigner_id'  => $plan->assignerId,
            'instance_id'  => $plan->instanceId,
            'entity_type'  => $plan->entityType,
            'entity_id'    => $plan->entityId,
            'status'       => $plan->status,
            'created_date' => ($plan->created ?? new DateTime())->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_MYSQL),
            'due_date'     => $plan->due ? $plan->due->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_MYSQL) : null,
            'data'         => $plan->data ? json_encode($plan->data) : null,
        ]);
    }

    public function update(Plan $original, Plan $plan, bool $notify = false, array $embedded = [], array $queueContext = [])
    {
        if (!$diff = $original->diff($plan)) {
            return null;
        }

        $this->db->transactional(function () use ($original, $plan, $notify, $diff, $embedded, $queueContext) {
            $diff['updated_at'] = (new DateTimeImmutable('now'))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::DATE_MYSQL);
            $this->createRevision($original);
            $this->db->update('gc_plan', $diff, ['id' => $original->id]);
            $plan->id = $original->id;
            $plan->original = $original;
            $plan->notify = $notify;

            $payload = $plan->jsonSerialize();
            $payload['embedded'] = $embedded + $this->planDeleteEventEmbedder->embedded($plan);
            $this->queue->publish($payload, Queue::PLAN_UPDATE, $queueContext);
        });
    }

    public function delete(int $id, array $embedded = [])
    {
        if (!$plan = $this->load($id)) {
            return null;
        }

        $this->db->delete('gc_plan', ['id' => $id]);

        $payload = $plan->jsonSerialize();
        $payload['embedded'] = $embedded + $this->planDeleteEventEmbedder->embedded($plan);
        $this->queue->publish($payload, Queue::PLAN_DELETE);
    }

    public function merge(Plan $plan, bool $notify = false, array $queueContext = [], array $embedded = [], bool $apiUpliftV3 = false): int
    {
        $qb = $this->db->createQueryBuilder();
        $original = $qb
            ->select('*')
            ->from('gc_plan', 'p')
            ->where($qb->expr()->eq('type', ':type'))
            ->andWhere($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->eq('instance_id', ':instanceId'))
            ->andWhere($qb->expr()->eq('entity_type', ':entityType'))
            ->andWhere($qb->expr()->eq('entity_id', ':entityId'))
            ->setParameters([
                ':type'       => $plan->type,
                ':userId'     => $plan->userId,
                ':instanceId' => $plan->instanceId,
                ':entityType' => $plan->entityType,
                ':entityId'   => $plan->entityId,
            ])
            ->execute()
            ->fetch(DB::OBJ);

        if ($original) {
            $original = Plan::create($original);
            if (false === $plan->due) {
                $plan->due = $original->due;
            }
            $plan->created = $original->created;
            $this->update($original, $plan, $notify, $embedded, $queueContext);
            $planId = $original->id;
        } else {
            $planId = $this->create($plan, $apiUpliftV3, $notify, $queueContext, $embedded);
        }

        return $planId;
    }

    public function archive(int $planId, array $embedded = [], array $queueContext = [], bool $isBatch = false): bool
    {
        if (!$plan = $this->load($planId)) {
            return false;
        }

        $this->db->transactional(function () use ($plan, $embedded, $queueContext, $isBatch) {
            $this->db->delete('gc_plan', ['id' => $plan->id]);
            $this->createRevision($plan);

            $payload = $plan->jsonSerialize();
            $queueContext['sessionId'] = Uuid::uuid4()->toString();
            $payload['embedded'] = $embedded + $this->planDeleteEventEmbedder->embedded($plan);
            if ($isBatch) {
                $this->queue->batchAdd($payload, Queue::PLAN_DELETE, $queueContext);
            } else {
                $this->queue->publish($payload, Queue::PLAN_DELETE, $queueContext);
            }
        });

        return true;
    }

    public function loadSuggestedPlan(string $entityType, int $entityId, int $userId): ?Plan
    {
        $q = $this->db->createQueryBuilder();
        $plan = $q
            ->select('*')
            ->from('gc_plan')
            ->where('type = :type')
            ->andWhere('entity_type = :entityType')
            ->andWhere('entity_id = :entityId')
            ->andWhere('user_id = :userId')
            ->setParameters([
                ':type'       => PlanTypes::SUGGESTED,
                ':entityType' => $entityType,
                ':entityId'   => $entityId,
                ':userId'     => $userId,
            ])
            ->execute()
            ->fetch(DB::OBJ);

        return $plan ? Plan::create($plan) : null;
    }

    /**
     * @return stdClass[]
     */
    public function loadUserPlanByEntity(int $portalId, int $userId, int $entityId, string $entityType = 'lo'): array
    {
        return $this->db->createQueryBuilder()
            ->select('*')
            ->from('gc_plan')
            ->where('entity_type = :entityType')
            ->andWhere('entity_id = :entityId')
            ->andWhere('user_id = :userId')
            ->andWhere('instance_id = :portalId')
            ->setParameter(':entityType', $entityType, DB::STRING)
            ->setParameter(':entityId', $entityId, DB::INTEGER)
            ->setParameter(':portalId', $portalId, DB::INTEGER)
            ->setParameter(':userId', $userId, DB::INTEGER)
            ->execute()
            ->fetchAll(DB::OBJ);
    }

    public function getDeferredMessages(): array
    {
        return $this->deferredMessages;
    }

    public function clearDeferredMessages(): void
    {
        $this->deferredMessages = [];
    }
}
