<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
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
 * - the connection address cannot be pinned, so DNS rebinding between the
 *   policy check and the connect is not excluded.
 *
 * @api
 */
final readonly class Fetcher
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private const array HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    private const string HTML_ACCEPT = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1';

    private const string JSON_ACCEPT = 'application/json';

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
        return $this->get($url, json: false, headers: []);
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
        return $this->get($url, json: true, headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    private function get(string $url, bool $json, array $headers): Result
    {
        $startedAt = (int) hrtime(as_number: true);
        $redirects = 0;
        $origin = null;

        while (true) {
            $checked = $this->policy->check(url: $url);

            if ($checked->isErr()) {
                return Result::err(error: FetchFailure::PolicyRejected);
            }

            $url = $checked->unwrap();
            $host = parse_url($url, PHP_URL_HOST);
            $host = \is_string($host) ? $host : '';
            $origin ??= $host;

            if ($redirects > 0 && $this->budgetSpent($startedAt)) {
                return Result::err(error: FetchFailure::BudgetExceeded);
            }

            $response = $this->send($url, $host, $json, $host === $origin ? $headers : []);

            if ($response === null) {
                return Result::err(error: FetchFailure::Network);
            }

            $status = $response->getStatusCode();

            if (!\in_array($status, self::REDIRECTS, true)) {
                return $this->page($url, $response, $redirects, $json);
            }

            if ($redirects >= $this->maxRedirects) {
                return Result::err(error: FetchFailure::TooManyRedirects);
            }

            $next = UrlResolver::resolve(base: $url, reference: $response->getHeaderLine('Location'));

            if ($next === null) {
                return Result::err(error: FetchFailure::InvalidRedirect);
            }

            $url = $next;
            ++$redirects;
        }
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
    private function send(string $url, string $host, bool $json, array $headers): ?ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest(method: 'GET', uri: $url)
            ->withHeader('User-Agent', $this->userAgents->forHost($host))
            ->withHeader('Accept', $json ? self::JSON_ACCEPT : self::HTML_ACCEPT);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface) {
            return null;
        }
    }

    /**
     * @param non-empty-string $url
     * @param int<0, max> $redirects
     *
     * @return Result<FetchedPage, FetchFailure>
     */
    private function page(string $url, ResponseInterface $response, int $redirects, bool $json): Result
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            return Result::err(error: FetchFailure::HttpStatus);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        $accepted = $json
            ? $mediaType === 'application/json' || str_ends_with($mediaType, '+json')
            : \in_array($mediaType, self::HTML_TYPES, true);

        if (!$accepted) {
            return Result::err(error: FetchFailure::NotHtml);
        }

        $declared = $response->getHeaderLine('Content-Length');

        if (preg_match('/\A\d+\z/', $declared) === 1 && (int) $declared > $this->maxBodyBytes) {
            return Result::err(error: FetchFailure::TooLarge);
        }

        $body = $this->read($response);

        if ($body === null) {
            return Result::err(error: FetchFailure::TooLarge);
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

    private function read(ResponseInterface $response): ?string
    {
        $stream = $response->getBody();
        $body = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (\strlen($body) > $this->maxBodyBytes) {
                return null;
            }
        }

        return $body;
    }
}
