<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Adapter;

/**
 * Why an {@see Adapter} produced nothing.
 *
 * @api
 */
enum AdapterFailure: string
{
    /** the service needs an access token and none was given */
    case MissingToken = 'missing_token';

    /** the URL is on the adapter's site but of a shape it cannot ask the service about */
    case UnsupportedUrl = 'unsupported_url';

    /** the service could not be reached, refused the request or answered with an error */
    case Unavailable = 'unavailable';

    /** the service answered, but not with anything the adapter can read */
    case InvalidResponse = 'invalid_response';
}
