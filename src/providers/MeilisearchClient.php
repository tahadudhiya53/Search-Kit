<?php

namespace Tahadudhiya\SearchKit\providers;

use Craft;
use craft\helpers\Json;
use GuzzleHttp\ClientInterface;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;

/**
 * Speaks Meilisearch's HTTP API. It is the only part of SearchKit that knows Meilisearch is
 * reached over HTTP, and the only part that ever holds its API key.
 */
class MeilisearchClient
{
    /** @var float How long a structural change may take to settle before it is called a failure. */
    public const TASK_TIMEOUT = 20.0;

    /** @var int How long before a task is first checked again. Most settle almost immediately. */
    private const FIRST_POLL_MICROSECONDS = 5_000;

    /** @var int The longest gap between two checks, once a task has proved to be a slow one. */
    private const MAX_POLL_MICROSECONDS = 100_000;

    private ?ClientInterface $_http = null;

    public function __construct(
        private readonly string $url,
        private readonly string $apiKey = '',
        private readonly float $timeout = 10.0,
    ) {
    }

    /**
     * @throws ProviderException
     */
    public function health(): bool
    {
        return ($this->request('GET', 'health')['status'] ?? null) === 'available';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws ProviderException
     */
    public function search(string $indexUid, array $body): array
    {
        return $this->request('POST', "indexes/$indexUid/search", $body);
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,mixed> The task Meilisearch enqueued.
     * @throws ProviderException
     */
    public function addDocuments(string $indexUid, array $documents): array
    {
        return $this->request('POST', "indexes/$indexUid/documents", $documents);
    }

    /**
     * @return array<string,mixed> The task Meilisearch enqueued.
     * @throws ProviderException
     */
    public function deleteDocument(string $indexUid, string $documentId): array
    {
        return $this->request('DELETE', "indexes/$indexUid/documents/" . rawurlencode($documentId));
    }

    /**
     * @return array<string,mixed> The task Meilisearch enqueued.
     * @throws ProviderException
     */
    public function createIndex(string $indexUid, string $primaryKey): array
    {
        return $this->request('POST', 'indexes', ['uid' => $indexUid, 'primaryKey' => $primaryKey]);
    }

    /**
     * @return array<string,mixed> The task Meilisearch enqueued.
     * @throws ProviderException
     */
    public function deleteIndex(string $indexUid): array
    {
        return $this->request('DELETE', "indexes/$indexUid");
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed> The task Meilisearch enqueued.
     * @throws ProviderException
     */
    public function updateSettings(string $indexUid, array $settings): array
    {
        return $this->request('PATCH', "indexes/$indexUid/settings", $settings);
    }

    /**
     * @throws ProviderException
     */
    public function indexExists(string $indexUid): bool
    {
        return $this->send('GET', "indexes/$indexUid")['status'] === 200;
    }

    /**
     * Waits for an enqueued change to land. Meilisearch accepts a change and applies it afterwards,
     * so this is the only point at which it can be said to have happened.
     *
     * @param array<string,mixed> $task As returned by whatever enqueued it.
     * @throws ProviderException
     */
    public function waitForTask(array $task, float $timeout = self::TASK_TIMEOUT): void
    {
        $uid = $task['taskUid'] ?? $task['uid'] ?? null;

        if (!is_int($uid)) {
            throw new ProviderException('Meilisearch did not say which task it enqueued.');
        }

        $deadline = microtime(true) + $timeout;
        $poll = self::FIRST_POLL_MICROSECONDS;

        do {
            $status = $this->request('GET', "tasks/$uid");
            $state = $status['status'] ?? null;

            if ($state === 'succeeded') {
                return;
            }

            if ($state === 'failed' || $state === 'canceled') {
                $error = is_array($status['error'] ?? null) ? $status['error'] : [];

                // The prose can quote the change Meilisearch refused, so only its machine-readable
                // code is repeated; the rest stays in the log.
                $this->log("Meilisearch task $uid $state: " . Json::encode($error));

                throw new ProviderException($this->taskFailureMessage($state, $error));
            }

            usleep($poll);
            $poll = min($poll * 2, self::MAX_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new ProviderException('Meilisearch did not finish applying a change to the index in time.');
    }

    /**
     * Waits until Meilisearch has nothing left queued against one index, so a change enqueued
     * earlier cannot land on an index that has since been discarded and recreated.
     *
     * @throws ProviderException
     */
    public function waitForIndexTasks(string $indexUid, float $timeout = self::TASK_TIMEOUT): void
    {
        $deadline = microtime(true) + $timeout;
        $poll = self::FIRST_POLL_MICROSECONDS;
        $query = http_build_query(['indexUids' => $indexUid, 'statuses' => 'enqueued,processing']);

        do {
            $outstanding = $this->request('GET', "tasks?$query")['results'] ?? null;

            if (!is_array($outstanding)) {
                throw new ProviderException('Meilisearch did not say what it still has queued.');
            }

            if ($outstanding === []) {
                return;
            }

            usleep($poll);
            $poll = min($poll * 2, self::MAX_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new ProviderException('Meilisearch still has changes queued against this index.');
    }

    /**
     * One request, with the response read as JSON and anything but success turned into a failure
     * whose detail stays in the log — a Meilisearch message can quote the request it rejected.
     *
     * @param array<mixed>|null $body
     * @return array<string,mixed>
     * @throws ProviderException
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $response = $this->send($method, $path, $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->log("Meilisearch answered $method $path with {$response['status']}: {$response['body']}");

            throw new ProviderException($this->failureMessage($response));
        }

        $decoded = Json::decodeIfJson($response['body']);

        // A body that is not a JSON object means the answer came from something other than the
        // Meilisearch API — a proxy or an error page. Read as “nothing”, it would look like an
        // empty index rather than a failure.
        if (!is_array($decoded)) {
            $this->log("Meilisearch answered $method $path with a body that is not JSON: {$response['body']}");

            throw new ProviderException('Meilisearch answered with something other than a JSON response.');
        }

        return $decoded;
    }

    /**
     * @param array<mixed>|null $body
     * @return array{status:int,body:string}
     * @throws ProviderException
     */
    protected function send(string $method, string $path, ?array $body = null): array
    {
        $options = ['http_errors' => false];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http()->request($method, $path, $options);
        } catch (Throwable $e) {
            // Never the exception's own message: it can quote the request that failed.
            $this->log("Meilisearch could not be reached for $method $path: {$e->getMessage()}");

            throw new ProviderException('The Meilisearch server could not be reached.', 0, $e);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string)$response->getBody(),
        ];
    }

    /**
     * @param array<string,mixed> $error What Meilisearch reported about the task it gave up on.
     */
    private function taskFailureMessage(string $state, array $error): string
    {
        $code = $error['code'] ?? null;

        return is_string($code)
            ? "Meilisearch $state to apply a change to the index ($code)."
            : "Meilisearch $state to apply a change to the index.";
    }

    /**
     * Meilisearch names what it refused in a stable machine code, which is safe to repeat and is
     * the one thing that makes a failure actionable. Its prose is not repeated.
     *
     * @param array{status:int,body:string} $response
     */
    private function failureMessage(array $response): string
    {
        $decoded = Json::decodeIfJson($response['body']);
        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;

        return is_string($code)
            ? "Meilisearch refused the request ($code)."
            : "Meilisearch answered with status {$response['status']}.";
    }

    private function http(): ClientInterface
    {
        return $this->_http ??= Craft::createGuzzleClient([
            'base_uri' => rtrim($this->url, '/') . '/',
            'timeout' => $this->timeout,
            'headers' => array_filter([
                'Authorization' => $this->apiKey !== '' ? "Bearer $this->apiKey" : null,
                'Content-Type' => 'application/json',
            ]),
        ]);
    }

    private function log(string $message): void
    {
        Craft::error($message, SearchKit::LOG_CATEGORY);
    }
}
