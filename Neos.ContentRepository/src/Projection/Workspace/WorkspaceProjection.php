<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Projection\Workspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Neos\ContentRepository\EventStore\EventNormalizer;
use Neos\ContentRepository\Infrastructure\DbalClientInterface;
use Neos\ContentRepository\Projection\ProjectionInterface;
use Neos\ContentRepository\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\ContentRepository\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Feature\WorkspaceRebase\Event\WorkspaceRebaseFailed;
use Neos\ContentRepository\Feature\WorkspaceCreation\Event\WorkspaceWasCreated;
use Neos\ContentRepository\Feature\WorkspaceDiscarding\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Feature\WorkspaceDiscarding\Event\WorkspaceWasPartiallyDiscarded;
use Neos\ContentRepository\Feature\WorkspacePublication\Event\WorkspaceWasPartiallyPublished;
use Neos\ContentRepository\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceName;
use Neos\EventStore\CatchUp\CatchUp;
use Neos\EventStore\DoctrineAdapter\DoctrineCheckpointStorage;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * @internal
 */
class WorkspaceProjection implements ProjectionInterface
{
    /**
     * @var WorkspaceFinder|null Cache for the workspace finder returned by {@see getState()}, so that always the same instance is returned
     */
    private ?WorkspaceFinder $workspaceFinder = null;
    private DoctrineCheckpointStorage $checkpointStorage;

    public function __construct(
        private readonly EventNormalizer $eventNormalizer,
        private readonly DbalClientInterface $dbalClient,
        private readonly string $tableName,
    ) {
        $this->checkpointStorage = new DoctrineCheckpointStorage(
            $this->dbalClient->getConnection(),
            $this->tableName . '_checkpoint',
            self::class
        );
    }

    public function setUp(): void
    {
        $this->setupTables();
        $this->checkpointStorage->setup();
    }

    private function setupTables(): void
    {
        $connection = $this->dbalClient->getConnection();
        $schemaManager = $connection->getSchemaManager();
        if (!$schemaManager instanceof AbstractSchemaManager) {
            throw new \RuntimeException('Failed to retrieve Schema Manager', 1625653914);
        }

        $schema = new Schema();
        $workspaceTable = $schema->createTable($this->tableName)
            ->addOption('collate', 'utf8mb4_unicode_ci');
        $workspaceTable->addColumn('workspacename', Types::STRING)
            ->setLength(255)
            ->setNotnull(true);
        $workspaceTable->addColumn('baseworkspacename', Types::STRING)
            ->setLength(255)
            ->setNotnull(false);
        $workspaceTable->addColumn('workspacetitle', Types::STRING)
            ->setLength(255)
            ->setNotnull(true);
        $workspaceTable->addColumn('workspacedescription', Types::STRING)
            ->setLength(255)
            ->setNotnull(true);
        $workspaceTable->addColumn('workspaceowner', Types::STRING)
            ->setLength(255)
            ->setNotnull(false);
        $workspaceTable->addColumn('currentcontentstreamidentifier', Types::STRING)
            ->setLength(255)
            ->setNotnull(false);
        $workspaceTable->addColumn('status', Types::STRING)
            ->setLength(50)
            ->setNotnull(false);

        $workspaceTable->setPrimaryKey(['workspacename']);

        $schemaDiff = (new Comparator())->compare($schemaManager->createSchema(), $schema);
        foreach ($schemaDiff->toSaveSql($connection->getDatabasePlatform()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function reset(): void
    {
        $this->getDatabaseConnection()->exec('TRUNCATE ' . $this->tableName);
    }

    public function canHandle(Event $event): bool
    {
        $eventClassName = $this->eventNormalizer->getEventClassName($event);
        return in_array($eventClassName, [
            WorkspaceWasCreated::class,
            RootWorkspaceWasCreated::class,
            WorkspaceWasDiscarded::class,
            WorkspaceWasPartiallyDiscarded::class,
            WorkspaceWasPartiallyPublished::class,
            WorkspaceWasPublished::class,
            WorkspaceWasRebased::class,
            WorkspaceRebaseFailed::class,
        ]);
    }

    public function catchUp(EventStreamInterface $eventStream): void
    {
        $catchUp = CatchUp::create($this->apply(...), $this->checkpointStorage);
        $catchUp->run($eventStream);
    }

    private function apply(EventEnvelope $eventEnvelope): void
    {
        if (!$this->canHandle($eventEnvelope->event)) {
            return;
        }

        $eventInstance = $this->eventNormalizer->denormalize($eventEnvelope->event);

        if ($eventInstance instanceof WorkspaceWasCreated) {
            $this->whenWorkspaceWasCreated($eventInstance);
        } elseif ($eventInstance instanceof RootWorkspaceWasCreated) {
            $this->whenRootWorkspaceWasCreated($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceWasDiscarded) {
            $this->whenWorkspaceWasDiscarded($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceWasPartiallyDiscarded) {
            $this->whenWorkspaceWasPartiallyDiscarded($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceWasPartiallyPublished) {
            $this->whenWorkspaceWasPartiallyPublished($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceWasPublished) {
            $this->whenWorkspaceWasPublished($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceWasRebased) {
            $this->whenWorkspaceWasRebased($eventInstance);
        } elseif ($eventInstance instanceof WorkspaceRebaseFailed) {
            $this->whenWorkspaceRebaseFailed($eventInstance);
        } else {
            throw new \RuntimeException('Not supported: ' . get_class($eventInstance));
        }
    }

    public function getSequenceNumber(): SequenceNumber
    {
        return $this->checkpointStorage->getHighestAppliedSequenceNumber();
    }

    public function getState(): WorkspaceFinder
    {
        if (!$this->workspaceFinder) {
            $this->workspaceFinder = new WorkspaceFinder(
                $this->dbalClient,
                $this->tableName
            );
        }
        return $this->workspaceFinder;
    }

    private function whenWorkspaceWasCreated(WorkspaceWasCreated $event): void
    {
        $this->getDatabaseConnection()->insert($this->tableName, [
            'workspaceName' => $event->getWorkspaceName(),
            'baseWorkspaceName' => $event->getBaseWorkspaceName(),
            'workspaceTitle' => $event->getWorkspaceTitle(),
            'workspaceDescription' => $event->getWorkspaceDescription(),
            'workspaceOwner' => $event->getWorkspaceOwner(),
            'currentContentStreamIdentifier' => $event->getNewContentStreamIdentifier(),
            'status' => Workspace::STATUS_UP_TO_DATE
        ]);
    }

    private function whenRootWorkspaceWasCreated(RootWorkspaceWasCreated $event): void
    {
        $this->getDatabaseConnection()->insert($this->tableName, [
            'workspaceName' => $event->getWorkspaceName(),
            'workspaceTitle' => $event->getWorkspaceTitle(),
            'workspaceDescription' => $event->getWorkspaceDescription(),
            'currentContentStreamIdentifier' => $event->getNewContentStreamIdentifier(),
            'status' => Workspace::STATUS_UP_TO_DATE
        ]);
    }

    private function whenWorkspaceWasDiscarded(WorkspaceWasDiscarded $event): void
    {
        $this->updateContentStreamIdentifier($event->getNewContentStreamIdentifier(), $event->getWorkspaceName());
        $this->markDependentWorkspacesAsOutdated($event->getWorkspaceName());
    }

    private function whenWorkspaceWasPartiallyDiscarded(WorkspaceWasPartiallyDiscarded $event): void
    {
        $this->updateContentStreamIdentifier($event->getNewContentStreamIdentifier(), $event->getWorkspaceName());
        $this->markDependentWorkspacesAsOutdated($event->getWorkspaceName());
    }

    private function whenWorkspaceWasPartiallyPublished(WorkspaceWasPartiallyPublished $event): void
    {
        // TODO: How do we test this method?
        // It's hard to design a BDD testcase failing if this method is commented out...
        $this->updateContentStreamIdentifier(
            $event->getNewSourceContentStreamIdentifier(),
            $event->getSourceWorkspaceName()
        );

        $this->markDependentWorkspacesAsOutdated($event->getTargetWorkspaceName());

        // NASTY: we need to set the source workspace name as non-outdated; as it has been made up-to-date again.
        $this->markWorkspaceAsUpToDate($event->getSourceWorkspaceName());

        $this->markDependentWorkspacesAsOutdated($event->getSourceWorkspaceName());
    }

    private function whenWorkspaceWasPublished(WorkspaceWasPublished $event): void
    {
        // TODO: How do we test this method?
        // It's hard to design a BDD testcase failing if this method is commented out...
        $this->updateContentStreamIdentifier(
            $event->getNewSourceContentStreamIdentifier(),
            $event->getSourceWorkspaceName()
        );

        $this->markDependentWorkspacesAsOutdated($event->getTargetWorkspaceName());

        // NASTY: we need to set the source workspace name as non-outdated; as it has been made up-to-date again.
        $this->markWorkspaceAsUpToDate($event->getSourceWorkspaceName());

        $this->markDependentWorkspacesAsOutdated($event->getSourceWorkspaceName());
    }

    private function whenWorkspaceWasRebased(WorkspaceWasRebased $event): void
    {
        $this->updateContentStreamIdentifier($event->getNewContentStreamIdentifier(), $event->getWorkspaceName());
        $this->markDependentWorkspacesAsOutdated($event->getWorkspaceName());

        // When the rebase is successful, we can set the status of the workspace back to UP_TO_DATE.
        $this->markWorkspaceAsUpToDate($event->getWorkspaceName());
    }

    private function whenWorkspaceRebaseFailed(WorkspaceRebaseFailed $event): void
    {
        $this->markWorkspaceAsOutdatedConflict($event->getWorkspaceName());
    }

    private function updateContentStreamIdentifier(
        ContentStreamIdentifier $contentStreamIdentifier,
        WorkspaceName $workspaceName
    ): void {
        $this->getDatabaseConnection()->update($this->tableName, [
            'currentContentStreamIdentifier' => $contentStreamIdentifier
        ], [
            'workspaceName' => $workspaceName
        ]);
    }

    private function markWorkspaceAsUpToDate(WorkspaceName $workspaceName): void
    {
        $this->getDatabaseConnection()->executeUpdate('
            UPDATE ' . $this->tableName . '
            SET status = :upToDate
            WHERE
                workspacename = :workspaceName
        ', [
            'upToDate' => Workspace::STATUS_UP_TO_DATE,
            'workspaceName' => $workspaceName->jsonSerialize()
        ]);
    }

    private function markDependentWorkspacesAsOutdated(WorkspaceName $baseWorkspaceName): void
    {
        $this->getDatabaseConnection()->executeUpdate('
            UPDATE ' . $this->tableName . '
            SET status = :outdated
            WHERE
                baseworkspacename = :baseWorkspaceName
        ', [
            'outdated' => Workspace::STATUS_OUTDATED,
            'baseWorkspaceName' => $baseWorkspaceName->jsonSerialize()
        ]);
    }

    private function markWorkspaceAsOutdatedConflict(WorkspaceName $workspaceName): void
    {
        $this->getDatabaseConnection()->executeUpdate('
            UPDATE ' . $this->tableName . '
            SET
                status = :outdatedConflict,
                foo = bar
            WHERE
                workspacename = :workspaceName
        ', [
            'outdatedConflict' => Workspace::STATUS_OUTDATED_CONFLICT,
            'workspaceName' => $workspaceName->jsonSerialize()
        ]);
    }

    private function getDatabaseConnection(): Connection
    {
        return $this->dbalClient->getConnection();
    }
}
