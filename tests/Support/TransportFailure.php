<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * @internal
 */
final class TransportFailure extends RuntimeException implements ClientExceptionInterface {}
