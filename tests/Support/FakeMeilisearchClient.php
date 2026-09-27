<?php

namespace Tahadudhiya\SearchKit\Tests\Support;

use craft\helpers\Json;
use Tahadudhiya\SearchKit\providers\MeilisearchClient;

/**
 * A Meilisearch client that answers from a script instead of over the network. Only the sending is
 * replaced, so everything the real client does with a response is still exercised.
 */
class FakeMeilisearchClient extends MeilisearchClient
{
    /** @var array<int,array{method:string,path:string,body:array<mixed>|null}> Every call made. */
    public array $requests = [];

    /** @var array<string,array{status:int,body:string}> Answers keyed by “METHOD path”. */
    public array $answers = [];

    /** @var array{status:int,body:string}|null Answered for anything the endpoint defaults do not cover. */
    public ?array $fallback = null;

    public function __construct()
    {
        parent::__construct('http://meilisearch.test');
    }

    /**
     * @param array<mixed> $body
     */
    public function willAnswer(string $method, string $path, array $body, int $status = 200): void
    {
        $this->answers["$method $path"] = ['status' => $status, 'body' => Json::encode($body)];
    }

    /**
     * Answers with a body exactly as given, for a test about something that is not valid JSON.
     */
    public function willAnswerRaw(string $method, string $path, string $body, int $status = 200): void
    {
        $this->answers["$method $path"] = ['status' => $status, 'body' => $body];
    }

    /**
     * Has an enqueued change come back as one Meilisearch accepted and then gave up on, which is
     * the shape of a failure that cannot be seen in the response to the write itself.
     */
    public function willFailTask(string $method, string $path, int $taskUid, string $code): void
    {
        $this->willAnswer($method, $path, ['taskUid' => $taskUid, 'status' => 'enqueued'], 202);
        $this->willAnswer('GET', "tasks/$taskUid", ['status' => 'failed', 'error' => ['code' => $code]]);
    }

    /**
     * @return string[] Every call made, as “METHOD path”, in the order they were made.
     */
    public function trail(): array
    {
        return array_map(
            static fn(array $request) => $request['method'] . ' ' . $request['path'],
            $this->requests,
        );
    }

    /**
     * @return array<int,array<string,mixed>> The bodies of every request to one path.
     */
    public function bodiesFor(string $method, string $path): array
    {
        return array_values(array_map(
            static fn(array $request) => (array)$request['body'],
            array_filter(
                $this->requests,
                static fn(array $request) => $request['method'] === $method && $request['path'] === $path,
            ),
        ));
    }

    /**
     * @return array<string,mixed> The body of the only request to one path.
     */
    public function bodyFor(string $method, string $path): array
    {
        $bodies = $this->bodiesFor($method, $path);

        return $bodies[0] ?? [];
    }

    protected function send(string $method, string $path, ?array $body = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

        return $this->answers["$method $path"] ?? $this->fallback ?? [
            'status' => 200,
            'body' => Json::encode($this->defaultBody($path)),
        ];
    }

    /**
     * What a healthy Meilisearch would answer each endpoint with, so a test only has to script the
     * call it is actually about.
     *
     * @return array<string,mixed>
     */
    private function defaultBody(string $path): array
    {
        if (str_starts_with($path, 'tasks?')) {
            return ['results' => []];
        }

        if (str_ends_with($path, '/search')) {
            return ['hits' => [], 'totalHits' => 0];
        }

        // Everything else either enqueues a task or reports one, and both are read the same way.
        return ['taskUid' => 0, 'status' => 'succeeded'];
    }
}
