<?php

namespace Tahadudhiya\SearchKit\services;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\enums\IndexOperationType;
use Tahadudhiya\SearchKit\models\IndexOperation;
use yii\base\Component;

/**
 * Stores the indexing work an index still owes, so nothing is lost between a content change and
 * the queue getting round to it.
 */
class IndexOperations extends Component
{
    /**
     * Records work for an element, replacing whatever was outstanding for it. The newest intent
     * wins: an element saved and then deleted only needs deleting.
     *
     * Every write issues a fresh token, which is what lets a worker tell the intent it picked up
     * from one that replaced it while it was busy.
     */
    public function record(
        int $indexId,
        int $elementId,
        int $siteId,
        string $elementType,
        IndexOperationType $operation,
    ): void {
        $token = StringHelper::UUID();

        Db::upsert(Table::INDEXOPERATIONS, [
            'indexId' => $indexId,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
            'operation' => $operation->value,
            'status' => IndexOperationStatus::Pending->value,
            'token' => $token,
            'attempts' => 0,
            'error' => null,
        ], [
            'elementType' => $elementType,
            'operation' => $operation->value,
            'status' => IndexOperationStatus::Pending->value,
            'token' => $token,
            'attempts' => 0,
            'error' => null,
        ]);
    }

    /**
     * The next pending operations for an index, oldest first.
     *
     * @return IndexOperation[]
     */
    public function getPending(int $indexId, int $limit, int $afterId = 0): array
    {
        $rows = $this->createQuery()
            ->where([
                'indexId' => $indexId,
                'status' => IndexOperationStatus::Pending->value,
            ])
            ->andWhere(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row) => $this->createOperationFromRow($row), $rows);
    }

    /**
     * @return IndexOperation[]
     */
    public function getFailed(int $indexId, int $limit = 50): array
    {
        $rows = $this->createQuery()
            ->where([
                'indexId' => $indexId,
                'status' => IndexOperationStatus::Failed->value,
            ])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row) => $this->createOperationFromRow($row), $rows);
    }

    public function countByStatus(int $indexId, IndexOperationStatus $status): int
    {
        return (int)(new Query())
            ->from([Table::INDEXOPERATIONS])
            ->where(['indexId' => $indexId, 'status' => $status->value])
            ->count();
    }

    public function hasPending(int $indexId): bool
    {
        return (new Query())
            ->from([Table::INDEXOPERATIONS])
            ->where(['indexId' => $indexId, 'status' => IndexOperationStatus::Pending->value])
            ->exists();
    }

    /**
     * Removes a completed operation, but only the exact intent that was processed. A newer intent
     * recorded while the worker was busy carries a different token and is left to be processed.
     *
     * @return bool Whether the processed intent was still the current one.
     */
    public function release(IndexOperation $operation): bool
    {
        if ($operation->id === null) {
            return false;
        }

        return Db::delete(Table::INDEXOPERATIONS, [
            'id' => $operation->id,
            'token' => $operation->token,
        ]) > 0;
    }

    /**
     * Records a failed attempt against the intent that was processed. It stays pending until its
     * retries are used up, then it is parked as failed for an administrator to look at.
     *
     * @param bool $permanent Retrying cannot help, so park it now.
     * @return bool Whether the failure was recorded, or false if a newer intent had replaced it.
     */
    public function recordFailure(IndexOperation $operation, string $error, int $maxAttempts, bool $permanent = false): bool
    {
        if ($operation->id === null) {
            return false;
        }

        $attempts = $operation->attempts + 1;
        $status = $permanent || $attempts >= $maxAttempts
            ? IndexOperationStatus::Failed
            : IndexOperationStatus::Pending;

        $updated = Db::update(Table::INDEXOPERATIONS, [
            'attempts' => $attempts,
            'error' => $error,
            'status' => $status->value,
        ], [
            'id' => $operation->id,
            'token' => $operation->token,
        ]) > 0;

        if ($updated) {
            $operation->attempts = $attempts;
            $operation->error = $error;
            $operation->status = $status;
        }

        return $updated;
    }

    /**
     * Puts every failed operation back in the queue with a clean slate. Pending intents are never
     * touched, so a retry can never overwrite newer work.
     */
    public function retryFailed(int $indexId): int
    {
        return Db::update(Table::INDEXOPERATIONS, [
            'status' => IndexOperationStatus::Pending->value,
            'attempts' => 0,
            'error' => null,
        ], [
            'indexId' => $indexId,
            'status' => IndexOperationStatus::Failed->value,
        ]);
    }

    /**
     * Drops outstanding work a rebuild is about to redo anyway. Deletions are only dropped when the
     * provider is discarding its whole index, since nothing else would carry them out.
     */
    public function deleteSupersededByRebuild(int $indexId, bool $includingDeletions): int
    {
        $condition = ['indexId' => $indexId];

        if (!$includingDeletions) {
            $condition = ['and', $condition, ['operation' => IndexOperationType::Index->value]];
        }

        return Db::delete(Table::INDEXOPERATIONS, $condition);
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'indexId', 'elementId', 'siteId', 'elementType', 'operation',
                'status', 'token', 'attempts', 'error', 'dateUpdated',
            ])
            ->from([Table::INDEXOPERATIONS]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createOperationFromRow(array $row): IndexOperation
    {
        return new IndexOperation([
            'id' => (int)$row['id'],
            'indexId' => (int)$row['indexId'],
            'elementId' => (int)$row['elementId'],
            'siteId' => (int)$row['siteId'],
            'elementType' => (string)$row['elementType'],
            'operation' => IndexOperationType::from($row['operation']),
            'status' => IndexOperationStatus::from($row['status']),
            'token' => (string)$row['token'],
            'attempts' => (int)$row['attempts'],
            'error' => $row['error'] !== null ? (string)$row['error'] : null,
            'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']) ?: null,
        ]);
    }
}
