<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

/**
 * @api
 */
enum JsonLdFailure: string
{
    case InvalidJson = 'invalid_json';

    /** a remote, inline or missing `@context` - nothing is ever fetched to find out */
    case UnsupportedContext = 'unsupported_context';
}
