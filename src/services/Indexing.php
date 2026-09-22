<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use Tahadudhiya\SearchKit\base\SearchProviderInterface;
use Tahadudhiya\SearchKit\enums\IndexOperationStatus;
use Tahadudhiya\SearchKit\enums\IndexOperationType;
use Tahadudhiya\SearchKit\enums\ProviderCapability;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\jobs\ProcessIndexOperations;
use Tahadudhiya\SearchKit\jobs\RebuildIndex;
use Tahadudhiya\SearchKit\models\IndexingResult;
use Tahadudhiya\SearchKit\models\IndexOperation;
use Tahadudhiya\SearchKit\models\IndexStatus;
use Tahadudhiya\SearchKit\models\ProviderStatus;
use Tahadudhiya\SearchKit\models\SearchDocument;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;

/**
 * Keeps indexes in step with Craft content: what needs indexing, when it happens, and what to do
 * when it fails.
 */
class Indexing extends Component
{
    /** @var int How often one operation is retried before it is parked for an administrator. */
    public const MAX_ATTEMPTS = 3;

    public const BATCH_SIZE = 100;

    /** @var string Shown instead of an unexpected exception, whose detail belongs in the log. */
    private const OPAQUE_FAILURE = 'The search provider failed. The cause is in the Search Kit log.';

    private ?Indexes $_indexes = null;
    private ?SearchableFields $_searchableFields = null;
    private ?Providers $_providers = null;
    private ?Documents $_documents = null;
    private ?IndexOperations $_operations = null;
    private ?Terms $_terms = null;
    private ?Normalization $_normalization = null;

    /**
     * Content changes must never break saving an element, so nothing here is allowed to escape.
     */
    public function handleElementSave(ElementInterface $element): void
    {
        $this->track($element, IndexOperationType::Index);
    }

    public function handleElementDelete(ElementInterface $element): void
    {
        $this->track($element, IndexOperationType::Delete);
        $this->forgetTerms($element);
    }

    public function handleElementRestore(ElementInterface $element): void
    {
        $this->track($element, IndexOperationType::Index);
    }

    /**
     * The enabled indexes that cover this element's site and are configured for its element type.
     *
     * @return SearchIndex[]
     */
    public function getIndexesForElement(ElementInterface $element): array
    {
        $matches = [];

        foreach ($this->getIndexes()->getAllIndexes() as $index) {
            if (!$index->enabled) {
                continue;
            }

            if ($element->siteId !== null && !$index->coversSite($element->siteId)) {
                continue;
            }

            $this->getSearchableFields()->attachFields($index);

            if ($index->getEnabledFields($element::class) !== []) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    /**
     * Works through everything an index owes. Only one run may touch an index at a time, so a
     * caller that is locked out is told so rather than being reported as having done nothing.
     *
     * @param callable(int,int):void|null $onProgress Called with the count done and the count found.
     */
    public function processPending(SearchIndex $index, ?callable $onProgress = null): IndexingResult
    {
        // A disabled index is not served, so it is not written to either.
        if ($index->id === null || !$index->enabled) {
            return new IndexingResult();
        }

        $mutex = Craft::$app->getMutex();
        $lockKey = $this->getLockKey($index);

        if (!$mutex->acquire($lockKey)) {
            return IndexingResult::locked();
        }

        try {
            $provider = $this->getProviders()->getProviderForIndex($index);
            $this->getSearchableFields()->attachFields($index);

            $result = new IndexingResult([
                'total' => $this->getOperations()->countByStatus($index->id, IndexOperationStatus::Pending),
            ]);

            $afterId = 0;

            while (($operations = $this->getOperations()->getPending($index->id, self::BATCH_SIZE, $afterId)) !== []) {
                foreach ($operations as $operation) {
                    $afterId = (int)$operation->id;

                    if ($this->executeOperation($index, $provider, $operation)) {
                        $result->processed++;
                    } else {
                        $result->failed++;
                    }

                    if ($onProgress !== null) {
                        $done = $result->processed + $result->failed;
                        $onProgress($done, max($result->total, $done));
                    }
                }
            }

            if ($result->isComplete() && $result->processed > 0) {
                // Clearing the rebuild is only earned once the work that rebuild left is gone.
                if ($index->rebuildPending && !$this->getOperations()->hasPending($index->id)) {
                    $this->getIndexes()->markRebuildComplete($index, $index->configurationVersion);
                } else {
                    $this->getIndexes()->updateLastIndexed($index);
                }
            }

            return $result;
        } finally {
            $mutex->release($lockKey);
        }
    }

    /**
     * Reindexes everything an index covers, under the same lock as ordinary processing so a rebuild
     * and a drain can never write to one provider index at once.
     *
     * @param callable(int,int):void|null $onProgress Called with the count done and the total.
     * @throws ProviderException
     */
    public function rebuild(SearchIndex $index, ?callable $onProgress = null): IndexingResult
    {
        // A disabled index is not served, so it is not written to either.
        if ($index->id === null || !$index->enabled) {
            return new IndexingResult();
        }

        $mutex = Craft::$app->getMutex();
        $lockKey = $this->getLockKey($index);

        if (!$mutex->acquire($lockKey)) {
            return IndexingResult::locked();
        }

        try {
            // Re-read under the lock so the walk uses the configuration that is actually current,
            // and remembers the generation it belongs to.
            $current = $this->getIndexes()->getIndexById($index->id) ?? $index;

            return $this->runRebuild($current, $onProgress);
        } finally {
            $mutex->release($lockKey);

            // Queued only after the lock is gone, so the worker it wakes is not turned away by it,
            // and in a finally so a rebuild that threw still leaves its work a way through.
            if ($this->getOperations()->hasPending($index->id)) {
                $this->queueProcessing($index);
            }
        }
    }

    /**
     * Puts failed operations back in the queue, and returns how many were reset.
     */
    public function retryFailed(SearchIndex $index): int
    {
        if ($index->id === null) {
            return 0;
        }

        $reset = $this->getOperations()->retryFailed($index->id);

        if ($reset > 0) {
            $this->queueProcessing($index);
        }

        return $reset;
    }

    /**
     * @return string|null The queue job's ID.
     */
    public function queueProcessing(SearchIndex $index): ?string
    {
        return $index->id !== null
            ? Queue::push(new ProcessIndexOperations(['indexId' => $index->id]))
            : null;
    }

    public function queueRebuild(SearchIndex $index): ?string
    {
        return $index->id !== null
            ? Queue::push(new RebuildIndex(['indexId' => $index->id]))
            : null;
    }

    public function getStatus(SearchIndex $index): IndexStatus
    {
        $status = new IndexStatus([
            'indexHandle' => $index->handle,
            'enabled' => $index->enabled,
            'dateLastIndexed' => $index->dateLastIndexed,
            'rebuildRequired' => $index->rebuildRequired,
        ]);

        if ($index->id !== null) {
            $status->pending = $this->getOperations()->countByStatus($index->id, IndexOperationStatus::Pending);
            $status->failed = $this->getOperations()->countByStatus($index->id, IndexOperationStatus::Failed);
        }

        try {
            $provider = $this->getProviders()->getProviderForIndex($index);
            $status->provider = $provider->status($index);
            $status->canIndex = $provider->supports(ProviderCapability::Indexing);
            $status->canDelete = $provider->supports(ProviderCapability::Deleting);
        } catch (ProviderException $e) {
            // Already safe for administrators: Providers keeps the detail in the log.
            $status->provider = ProviderStatus::unavailable($e->getMessage());
        }

        return $status;
    }

    /**
     * @throws ProviderException
     */
    private function runRebuild(SearchIndex $index, ?callable $onProgress): IndexingResult
    {
        $provider = $this->getProviders()->getProviderForIndex($index);
        $this->getSearchableFields()->attachFields($index);
        $configurationVersion = $index->configurationVersion;

        if (!$provider->supports(ProviderCapability::Indexing)) {
            throw UnsupportedCapabilityException::for($provider::displayName(), ProviderCapability::Indexing);
        }

        // Outstanding indexing is about to be redone. Outstanding deletions are only dropped when
        // the provider is discarding its whole index, since nothing else would carry them out.
        $discards = $provider->supports(ProviderCapability::Rebuilding);
        $this->getOperations()->deleteSupersededByRebuild((int)$index->id, $discards);

        if ($discards) {
            $provider->rebuild($index);
        }

        // The words the index held were drawn from content this walk is about to replace, so they
        // are dropped first: a rebuild is the only thing that can tell which of them are still real.
        $this->getTerms()->clear((int)$index->id);

        $scopes = $this->rebuildScopes($index);
        $result = new IndexingResult();

        foreach ($scopes as [$elementType, $siteId]) {
            $result->total += (int)$this->rebuildQuery($elementType, $siteId)->count();
        }

        foreach ($scopes as [$elementType, $siteId]) {
            $offset = 0;

            while (($elements = $this->rebuildQuery($elementType, $siteId)->offset($offset)->limit(self::BATCH_SIZE)->all()) !== []) {
                foreach ($elements as $element) {
                    if ($this->indexDuringRebuild($index, $provider, $element)) {
                        $result->processed++;
                    } else {
                        $result->failed++;
                    }

                    if ($onProgress !== null) {
                        $done = $result->processed + $result->failed;
                        $onProgress($done, max($result->total, $done));
                    }
                }

                $offset += self::BATCH_SIZE;
            }
        }

        // Content changed during the walk stays outstanding and is processed afterwards, so the
        // index is only current when the walk itself left nothing behind. Either way the write is
        // refused if the configuration moved on: this rebuild covered the older one.
        $settled = $result->isComplete()
            ? $this->getIndexes()->markRebuildComplete($index, $configurationVersion)
            : $this->getIndexes()->markRebuildIncomplete($index, $configurationVersion);

        if (!$settled) {
            $result->superseded = true;

            Craft::info(
                "A rebuild of “{$index->handle}” finished against configuration {$configurationVersion}, "
                . 'which is no longer current. The index still needs rebuilding.',
                SearchKit::LOG_CATEGORY,
            );
        }

        return $result;
    }

    private function track(ElementInterface $element, IndexOperationType $operation): void
    {
        try {
            if ($element->id === null || $element->siteId === null || ElementHelper::isDraftOrRevision($element)) {
                return;
            }

            foreach ($this->getIndexesForElement($element) as $index) {
                $provider = $this->getProviders()->getProviderForIndex($index);

                // A provider that cannot delete is not owed deletions — Craft's own index is one.
                if (!$provider->supports($operation->capability())) {
                    continue;
                }

                // Queue only when an idle index starts owing work: while anything is outstanding a
                // job is already on its way, and it drains everything it finds.
                $wasIdle = !$this->getOperations()->hasPending((int)$index->id);

                $this->getOperations()->record(
                    (int)$index->id,
                    (int)$element->id,
                    (int)$element->siteId,
                    $element::class,
                    $operation,
                );

                if ($wasIdle) {
                    $this->queueProcessing($index);
                }
            }
        } catch (Throwable $e) {
            Craft::error("Could not track element {$element->id} for indexing: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
        }
    }

    /**
     * @return bool Whether the provider accepted the operation.
     */
    private function executeOperation(SearchIndex $index, SearchProviderInterface $provider, IndexOperation $operation): bool
    {
        // Retrying cannot make a provider gain a capability, so this is parked rather than retried.
        if (!$provider->supports($operation->operation->capability())) {
            $this->recordFailure(
                $index,
                $operation,
                UnsupportedCapabilityException::for($provider::displayName(), $operation->operation->capability())->getMessage(),
                permanent: true,
            );

            return false;
        }

        try {
            if ($operation->operation === IndexOperationType::Delete) {
                $this->getTerms()->forgetDocument($operation->elementId, $operation->siteId, (int)$index->id);

                $provider->deleteDocument($index, SearchDocument::forDeletion(
                    $operation->elementId,
                    $operation->siteId,
                    $operation->elementType,
                    $index->handle,
                ));

                $this->getOperations()->release($operation);
                return true;
            }

            /** @var class-string<ElementInterface> $elementType */
            $elementType = $operation->elementType;
            $element = Craft::$app->getElements()->getElementById($operation->elementId, $elementType, $operation->siteId);

            // Gone before the queue got to it, or carrying nothing this index is configured for.
            $document = $element !== null ? $this->getDocuments()->buildDocument($index, $element) : null;

            if ($document !== null && !$document->isEmpty()) {
                $provider->indexDocument($index, $document);
                $this->recordTerms($index, $document);
            } else {
                // Either way it contributes no words now, so whatever it contributed before goes.
                $this->getTerms()->forgetDocument($operation->elementId, $operation->siteId, (int)$index->id);
            }

            $this->getOperations()->release($operation);
            return true;
        } catch (Throwable $e) {
            $this->recordFailure($index, $operation, $this->safeMessage($e), detail: $e->getMessage());
            return false;
        }
    }

    /**
     * A rebuild failure becomes outstanding work rather than stopping the rebuild.
     */
    private function indexDuringRebuild(SearchIndex $index, SearchProviderInterface $provider, ElementInterface $element): bool
    {
        try {
            $document = $this->getDocuments()->buildDocument($index, $element);

            if ($document->isEmpty()) {
                return true;
            }

            $provider->indexDocument($index, $document);
            $this->recordTerms($index, $document);

            return true;
        } catch (Throwable $e) {
            Craft::error(
                "Could not index element {$element->id} while rebuilding “{$index->handle}”: {$e->getMessage()}",
                SearchKit::LOG_CATEGORY,
            );

            $this->getOperations()->record(
                (int)$index->id,
                (int)$element->id,
                (int)$element->siteId,
                $element::class,
                IndexOperationType::Index,
            );

            return false;
        }
    }

    /**
     * Keeps the words behind completions and corrections, replacing whatever this document
     * contributed before. A failure here is a real failure: the provider now holds a document the
     * dictionary does not describe, so the operation stays outstanding and is retried rather than
     * being settled on a half-finished write.
     */
    private function recordTerms(SearchIndex $index, SearchDocument $document): void
    {
        $terms = [];

        // Reduced in the document's own site language, which is what Craft indexes its keywords in.
        $language = $this->getNormalization()->siteLanguage($document->siteId);

        foreach ($document->getFields() as $value) {
            foreach ($this->getNormalization()->terms($value, $language) as $term) {
                $terms[] = $term;
            }
        }

        $this->getTerms()->record(
            (int)$index->id,
            $document->elementId,
            $document->siteId,
            $document->elementType,
            $terms,
        );
    }

    /**
     * An element that has gone contributes no words to any index, whether or not its provider has
     * anything to delete — the words are SearchKit's own, not the provider's.
     */
    private function forgetTerms(ElementInterface $element): void
    {
        try {
            if ($element->id === null || $element->siteId === null || ElementHelper::isDraftOrRevision($element)) {
                return;
            }

            $this->getTerms()->forgetDocument((int)$element->id, (int)$element->siteId);
        } catch (Throwable $e) {
            Craft::error("Could not forget the words of element {$element->id}: {$e->getMessage()}", SearchKit::LOG_CATEGORY);
        }
    }

    private function recordFailure(
        SearchIndex $index,
        IndexOperation $operation,
        string $message,
        bool $permanent = false,
        ?string $detail = null,
    ): void {
        Craft::error(
            "Indexing operation {$operation->id} failed on “{$index->handle}”: " . ($detail ?? $message),
            SearchKit::LOG_CATEGORY,
        );

        $this->getOperations()->recordFailure($operation, $message, self::MAX_ATTEMPTS, $permanent);
    }

    /**
     * SearchKit's own failures are written to be shown; anything else could carry internals.
     */
    private function safeMessage(Throwable $e): string
    {
        return $e instanceof SearchKitException ? $e->getMessage() : self::OPAQUE_FAILURE;
    }

    /**
     * The lock every write to one index takes, so a drain and a rebuild can never overlap.
     */
    public function getLockKey(SearchIndex $index): string
    {
        return "searchkit:index:$index->id";
    }

    /**
     * The element type and site pairs a rebuild has to walk — exactly the pairs that would have
     * produced operations on save, so a rebuild can never reach outside the index's scope.
     *
     * @return array<array{class-string<ElementInterface>,int}>
     */
    private function rebuildScopes(SearchIndex $index): array
    {
        $scopes = [];

        /** @var class-string<ElementInterface> $elementType */
        foreach ($index->getElementTypes() as $elementType) {
            foreach ($this->siteIdsFor($elementType) as $siteId) {
                if ($index->coversSite($siteId)) {
                    $scopes[] = [$elementType, $siteId];
                }
            }
        }

        return $scopes;
    }

    /**
     * An element type that is not localized only ever belongs to the primary site, so it is never
     * walked once per site.
     *
     * @param class-string<ElementInterface> $elementType
     * @return int[]
     */
    private function siteIdsFor(string $elementType): array
    {
        if (!$elementType::isLocalized()) {
            return [(int)Craft::$app->getSites()->getPrimarySite()->id];
        }

        return array_map(static fn($siteId) => (int)$siteId, Craft::$app->getSites()->getAllSiteIds());
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    private function rebuildQuery(string $elementType, int $siteId): ElementQueryInterface
    {
        return $elementType::find()
            ->siteId($siteId)
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC]);
    }

    public function setIndexes(Indexes $indexes): void
    {
        $this->_indexes = $indexes;
    }

    public function getIndexes(): Indexes
    {
        return $this->_indexes ??= SearchKit::instance()->getIndexes();
    }

    public function setSearchableFields(SearchableFields $searchableFields): void
    {
        $this->_searchableFields = $searchableFields;
    }

    public function getSearchableFields(): SearchableFields
    {
        return $this->_searchableFields ??= SearchKit::instance()->getSearchableFields();
    }

    public function setProviders(Providers $providers): void
    {
        $this->_providers = $providers;
    }

    public function getProviders(): Providers
    {
        return $this->_providers ??= SearchKit::instance()->getProviders();
    }

    public function setDocuments(Documents $documents): void
    {
        $this->_documents = $documents;
    }

    public function getDocuments(): Documents
    {
        return $this->_documents ??= SearchKit::instance()->getDocuments();
    }

    public function setOperations(IndexOperations $operations): void
    {
        $this->_operations = $operations;
    }

    public function getOperations(): IndexOperations
    {
        return $this->_operations ??= SearchKit::instance()->getIndexOperations();
    }

    public function setTerms(Terms $terms): void
    {
        $this->_terms = $terms;
    }

    public function getTerms(): Terms
    {
        return $this->_terms ??= SearchKit::instance()->getTerms();
    }

    public function setNormalization(Normalization $normalization): void
    {
        $this->_normalization = $normalization;
    }

    public function getNormalization(): Normalization
    {
        return $this->_normalization ??= SearchKit::instance()->getNormalization();
    }
}
