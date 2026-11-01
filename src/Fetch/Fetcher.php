<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Url\UrlResolver;
use Rasuvaeff\Result\Result;

/**
 * Fetches an HTML page through a PSR-18 client, following redirects itself so
 * that {@see UrlPolicy} judges every hop before any request is made to it.
 *
 * What PSR-18 cannot give, this class does not promise:
 *
 * - the client MUST be configured not to follow redirects (Guzzle:
 *   `allow_redirects => false`, Symfony: `max_redirects => 0`); a redirect the
 *   client follows on its own is invisible here and skips the policy;
 * - connect and read timeouts belong to the client's own configuration;
 * - `chainBudgetSeconds` is checked between hops only and cannot interrupt a
 *   request in flight;
 * - the response size limit is applied to the body as the client hands it
 *   over — a client that buffers has already paid for the bytes. Set the
 *   client's own limit as well;
 * - the connection address cannot be pinned by a plain PSR-18 client, so DNS
 *   rebinding between the policy check and the connect is not excluded - give
 *   it a pinning client (`rasuvaeff/pinned-http-client`) to close that.
 *
 * Client exceptions are classified by their PSR-18 interface:
 * `NetworkExceptionInterface` is {@see FetchFailureReason::Network} (transient),
 * any other `ClientExceptionInterface` is {@see FetchFailureReason::ClientRefused}.
 *
 * @api
 */
final readonly class Fetcher
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private const array HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    private const string HTML_ACCEPT = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1';

    private const string JSON_ACCEPT = 'application/json';

    private const string MEDIA_TYPE = '~\A[a-z0-9][a-z0-9!#$&^_.+-]*/(?:\*|[a-z0-9][a-z0-9!#$&^_.+-]*)\z~';

    /**
     * @param float|null $chainBudgetSeconds wall-clock budget of the whole redirect chain
     */
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private UrlPolicy $policy = new UrlPolicy(),
        private UserAgentMap $userAgents = new UserAgentMap(),
        private int $maxRedirects = 5,
        private int $maxBodyBytes = 2_097_152,
        private ?float $chainBudgetSeconds = null,
    ) {
        if ($maxRedirects < 0) {
            throw new InvalidArgumentException('Max redirects must not be negative');
        }

        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Max body size must be positive');
        }

        if ($chainBudgetSeconds !== null && $chainBudgetSeconds < 0.0) {
            throw new InvalidArgumentException('Chain budget must not be negative');
        }
    }

    /**
     * The URL as it would be requested, or why the policy refuses it.
     *
     * @return Result<non-empty-string, UrlRejection>
     */
    public function check(string $url): Result
    {
        return $this->policy->check(url: $url);
    }

    /**
     * Fetches an HTML page (`text/html` or `application/xhtml+xml`).
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    public function fetch(string $url): Result
    {
        return $this->get(
            url: $url,
            accept: self::HTML_ACCEPT,
            accepts: static fn(string $type): bool => \in_array($type, self::HTML_TYPES, strict: true),
            headers: [],
            maxBytes: $this->maxBodyBytes,
        );
    }

    /**
     * Fetches a JSON document (`application/json` or any `+json` type) under
     * exactly the same policy, redirect and size rules as a page.
     *
     * `$headers` (an API token, for instance) are sent to the host of the
     * first URL only: a redirect to another host drops them, so a credential
     * cannot be handed to a third party by a `Location` header.
     *
     * @param array<string, string> $headers
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    public function fetchJson(string $url, #[\SensitiveParameter] array $headers = []): Result
    {
        return $this->get(
            url: $url,
            accept: self::JSON_ACCEPT,
            accepts: static fn(string $type): bool => $type === 'application/json' || str_ends_with($type, '+json'),
            headers: $headers,
            maxBytes: $this->maxBodyBytes,
        );
    }

    /**
     * Fetches a media file - an image of a preview, say - under the same policy,
     * redirect and `User-Agent` rules as a page, with its own size limit.
     *
     * @param list<string> $acceptedTypes media types (`image/png`) or whole families (`image/*`), lower-case
     * @param int $maxBytes the body limit of this fetch, at least 1; it replaces `maxBodyBytes`. A client with a
     *        body limit of its own (`PinnedClient::$maxBodyBytes`) must allow at least this much, or a larger
     *        body is {@see FetchFailureReason::ClientRefused}
     *
     * @throws InvalidArgumentException when no type is given, a type is malformed or the limit is not positive
     *
     * @return Result<FetchedPage, FetchFailure> the body in `FetchedPage::$body`, its type in `$contentType`
     */
    public function fetchMedia(string $url, array $acceptedTypes, int $maxBytes): Result
    {
        if ($acceptedTypes === []) {
            throw new InvalidArgumentException('At least one media type must be accepted');
        }

        foreach ($acceptedTypes as $type) {
            if (preg_match(self::MEDIA_TYPE, $type) !== 1) {
                throw new InvalidArgumentException(\sprintf('Invalid media type "%s"', $type));
            }
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Max body size must be positive');
        }

        return $this->get(
            url: $url,
            accept: implode(', ', $acceptedTypes),
            accepts: static function (string $type) use ($acceptedTypes): bool {
                foreach ($acceptedTypes as $accepted) {
                    if ($type === $accepted || (str_ends_with($accepted, '/*') && str_starts_with($type, substr($accepted, 0, -1)))) {
                        return true;
                    }
                }

                return false;
            },
            headers: [],
            maxBytes: $maxBytes,
        );
    }

    /**
     * @param \Closure(string): bool $accepts whether a media type is what was asked for
     * @param array<string, string> $headers
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    private function get(string $url, string $accept, \Closure $accepts, array $headers, int $maxBytes): Result
    {
        $startedAt = (int) hrtime(as_number: true);
        $redirects = 0;
        $origin = null;

        while (true) {
            $checked = $this->policy->check(url: $url);

            if ($checked->isErr()) {
                return $this->failure(FetchFailureReason::PolicyRejected);
            }

            $url = $checked->unwrap();
            $host = parse_url($url, PHP_URL_HOST);
            $host = \is_string($host) ? $host : '';
            $origin ??= $host;

            if ($redirects > 0 && $this->budgetSpent($startedAt)) {
                return $this->failure(FetchFailureReason::BudgetExceeded);
            }

            $response = $this->send($url, $host, $accept, $host === $origin ? $headers : []);

            if ($response instanceof FetchFailureReason) {
                return $this->failure($response);
            }

            $status = $response->getStatusCode();

            if (!\in_array($status, self::REDIRECTS, strict: true)) {
                return $this->page($url, $response, $redirects, $accepts, $maxBytes);
            }

            if ($redirects >= $this->maxRedirects) {
                return $this->failure(FetchFailureReason::TooManyRedirects);
            }

            $next = UrlResolver::resolve(base: $url, reference: $response->getHeaderLine('Location'));

            if ($next === null) {
                return $this->failure(FetchFailureReason::InvalidRedirect);
            }

            $url = $next;
            ++$redirects;
        }
    }

    /**
     * @return Result<FetchedPage, FetchFailure>
     */
    private function failure(FetchFailureReason $reason): Result
    {
        return Result::err(error: new FetchFailure($reason));
    }

    private function budgetSpent(int $startedAt): bool
    {
        if ($this->chainBudgetSeconds === null) {
            return false;
        }

        return (float) (hrtime(as_number: true) - $startedAt) / 1e9 >= $this->chainBudgetSeconds;
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $url, string $host, string $accept, array $headers): ResponseInterface|FetchFailureReason
    {
        $request = $this->requestFactory
            ->createRequest(method: 'GET', uri: $url)
            ->withHeader('User-Agent', $this->userAgents->forHost($host))
            ->withHeader('Accept', $accept);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $this->client->sendRequest($request);
        } catch (NetworkExceptionInterface) {
            return FetchFailureReason::Network;
        } catch (ClientExceptionInterface) {
            return FetchFailureReason::ClientRefused;
        }
    }

    /**
     * @param non-empty-string $url
     * @param int<0, max> $redirects
     * @param \Closure(string): bool $accepts
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    private function page(string $url, ResponseInterface $response, int $redirects, \Closure $accepts, int $maxBytes): Result
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status > 299) {
            return Result::err(error: new FetchFailure(
                reason: FetchFailureReason::HttpStatus,
                status: $status >= 100 && $status <= 599 ? $status : null,
                retryAfterSeconds: RetryAfter::seconds($response),
            ));
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        if (!$accepts($mediaType)) {
            return $this->failure(FetchFailureReason::UnexpectedContentType);
        }

        $declared = $response->getHeaderLine('Content-Length');

        if (preg_match('/\A\d+\z/', $declared) === 1 && (int) $declared > $maxBytes) {
            return $this->failure(FetchFailureReason::TooLarge);
        }

        $body = $this->read($response, $maxBytes);

        if ($body === null) {
            return $this->failure(FetchFailureReason::TooLarge);
        }

        $charset = preg_match('/;\s*charset\s*=\s*"?([A-Za-z0-9._:-]+)/i', $contentType, $matches) === 1
            ? $matches[1]
            : null;

        return Result::ok(value: new FetchedPage(
            url: $url,
            body: $body,
            contentType: $mediaType,
            charset: $charset,
            redirects: $redirects,
        ));
    }

    private function read(ResponseInterface $response, int $maxBytes): ?string
    {
        $stream = $response->getBody();
        $body = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (\strlen($body) > $maxBytes) {
                return null;
            }
        }

        return $body;
    }
}
