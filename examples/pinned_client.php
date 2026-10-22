<?php

declare(strict_types=1);

// DNS pinning on a PSR-18 client: the host is resolved here, every address is checked with the same UrlPolicy, and the
// connection is pinned to the checked addresses (CURLOPT_RESOLVE), so a name rebound between the check and the connection
// cannot reach an internal host. A host with any non-public address is refused before a socket is opened.
//
// Needs guzzlehttp/guzzle with the curl handler; without it the script says so and exits.

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

if (!class_exists(Client::class) || !\defined('CURLOPT_RESOLVE')) {
    echo "Install guzzlehttp/guzzle (with ext-curl) to run this example.\n";

    return;
}

final class NoPublicAddress extends RuntimeException implements ClientExceptionInterface {}

final readonly class PinnedClient implements ClientInterface
{
    public function __construct(private UrlPolicy $policy, private float $timeout = 8.0, private float $connectTimeout = 4.0) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $addresses = $this->checked($host);

        if ($addresses === []) {
            throw new NoPublicAddress('No public address for ' . $host);
        }

        try {
            return (new Client())->send($request, [
                'allow_redirects' => false, // the Fetcher follows redirects itself, applying the policy to every hop
                'http_errors' => false,
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . implode(',', $addresses)], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ]);
        } catch (Throwable $failure) {
            throw new NoPublicAddress($failure->getMessage(), previous: $failure);
        }
    }

    /**
     * @return list<string> the IPv4 addresses of the host, or none when the name does not resolve or any address is not public
     */
    private function checked(string $host): array
    {
        $resolved = gethostbynamel($host);
        $found = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($resolved === false ? [] : $resolved);

        foreach ($found as $address) {
            if ($this->policy->check('http://' . $address . '/')->isErr()) {
                return [];
            }
        }

        return $found;
    }
}

$policy = new UrlPolicy();
$fetcher = new Fetcher(client: new PinnedClient($policy), requestFactory: new HttpFactory(), policy: $policy);

foreach (['http://127.0.0.1/', 'http://localhost/', 'http://169.254.169.254/latest/meta-data/', 'http://no-such-host.invalid/'] as $url) {
    $result = $fetcher->fetch($url);

    echo str_pad($url, 45), $result->isErr() ? 'refused (' . $result->error()->name . ')' : 'fetched', "\n";
}

// The client alone, as if the policy in front of it had been bypassed (or the name rebound after the check): a name that
// resolves to an internal address still never gets a connection.
try {
    (new PinnedClient($policy))->sendRequest((new HttpFactory())->createRequest('GET', 'http://localhost/'));
} catch (NoPublicAddress $refused) {
    echo str_pad('http://localhost/ (client alone)', 45), 'refused (', $refused->getMessage(), ")\n";
}
