<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A dropped connection, as a PSR-18 client reports it.
 *
 * @internal
 */
final class TransportFailure extends RuntimeException implements NetworkExceptionInterface
{
    #[\Override]
    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://example.com/');
    }
}
