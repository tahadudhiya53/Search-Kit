<?php

namespace Tahadudhiya\SearchKit\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SearchKit\errors\IndexDisabledException;
use Tahadudhiya\SearchKit\errors\IndexNotFoundException;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\errors\ProviderException;
use Tahadudhiya\SearchKit\errors\SearchKitException;
use Tahadudhiya\SearchKit\errors\UnauthorizedQueryException;
use Tahadudhiya\SearchKit\errors\UnsupportedCapabilityException;
use Tahadudhiya\SearchKit\models\ApiKey;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The HTTP search API. It authenticates the key, holds it to its rate, and hands everything else
 * to the same search service PHP and Twig use.
 */
class ApiController extends Controller
{
    /** @var string What an API key is presented in, so a key never travels in a URL or a log. */
    private const AUTH_HEADER = 'Authorization';

    // Authenticated by API key rather than by session, so there is no signed-in user to have one.
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    // Nothing here is authenticated by a cookie, so a cross-site request can borrow no authority.
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['search' => ['GET', 'POST']],
            ],
        ]);
    }

    public function actionSearch(): Response
    {
        $key = $this->plugin()->getApiKeys()->authenticate($this->presentedKey());

        if ($key === null) {
            return $this->error(401, 'unauthorized', 'A valid API key is required.');
        }

        $rate = $this->plugin()->getApiKeys()->checkRateLimit($key);
        $this->reportRate($rate);

        if ($rate['retryAfter'] !== null) {
            $this->response->getHeaders()->set('Retry-After', (string)$rate['retryAfter']);

            return $this->error(429, 'rate_limit_exceeded', 'Too many requests. Try again shortly.');
        }

        $this->plugin()->getApiKeys()->touch($key);

        return $this->runSearch($key);
    }

    private function runSearch(ApiKey $key): Response
    {
        try {
            return $this->asJson($this->plugin()->getApi()->search($key, $this->params()));
        } catch (InvalidQueryException $e) {
            return $this->error(400, 'invalid_query', $e->getMessage(), $e->getErrors());
        } catch (UnsupportedCapabilityException $e) {
            return $this->error(400, 'unsupported_query', $e->getMessage());
        } catch (IndexNotFoundException $e) {
            return $this->error(404, 'index_not_found', $e->getMessage());
        } catch (IndexDisabledException $e) {
            return $this->error(404, 'index_disabled', $e->getMessage());
        } catch (UnauthorizedQueryException $e) {
            return $this->error(403, 'forbidden', $e->getMessage());
        } catch (ProviderException $e) {
            return $this->error(502, 'provider_error', $e->getMessage());
        } catch (SearchKitException $e) {
            return $this->error(500, 'search_failed', $e->getMessage());
        } catch (Throwable $e) {
            // Anything unexpected is logged rather than described: a client is told nothing about
            // how this installation is put together.
            Craft::error("The search API failed: {$e->getMessage()}", SearchKit::LOG_CATEGORY);

            return $this->error(500, 'search_failed', 'The search could not be completed.');
        }
    }

    /**
     * What the request asked for. A body parameter wins, so a posted search is not confused by
     * whatever happens to be on the URL.
     *
     * @return array<string,mixed>
     */
    private function params(): array
    {
        return array_merge($this->request->getQueryParams(), $this->request->getBodyParams());
    }

    /**
     * The key a request presented. Only a bearer token is read: a key in a query string would be
     * kept by every log and proxy it passed through.
     */
    private function presentedKey(): ?string
    {
        $header = (string)$this->request->getHeaders()->get(self::AUTH_HEADER, '');

        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param array{limit:int|null,remaining:int|null,retryAfter:int|null} $rate
     */
    private function reportRate(array $rate): void
    {
        if ($rate['limit'] === null) {
            return;
        }

        $headers = $this->response->getHeaders();
        $headers->set('X-Rate-Limit-Limit', (string)$rate['limit']);
        $headers->set('X-Rate-Limit-Remaining', (string)($rate['remaining'] ?? 0));
    }

    /**
     * Every failure has the same shape, so a client can read one without knowing what went wrong.
     *
     * @param array<string,string[]> $details
     */
    private function error(int $status, string $code, string $message, array $details = []): Response
    {
        $this->response->setStatusCode($status);

        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return $this->asJson(['error' => $error]);
    }

    private function plugin(): SearchKit
    {
        $plugin = SearchKit::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('Search Kit is not installed.');
        }

        return $plugin;
    }
}
