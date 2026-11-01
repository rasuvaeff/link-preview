<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A request the client would not send - what a pinning client throws for a forbidden address.
 *
 * @internal
 */
final class RefusedRequest extends RuntimeException implements ClientExceptionInterface {}
