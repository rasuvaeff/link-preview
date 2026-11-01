<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * What went wrong with a fetch. {@see FetchFailure} carries it together with the
 * HTTP status and `Retry-After` of the answer, when there was one.
 *
 * @api
 */
enum FetchFailureReason: string
{
    /** the URL, or a redirect target, was turned down by the {@see UrlPolicy} */
    case PolicyRejected = 'policy_rejected';

    /** the PSR-18 client could not reach the host (`NetworkExceptionInterface`) */
    case Network = 'network';

    /**
     * the PSR-18 client refused the request with any other `ClientExceptionInterface` - a
     * pinning client found a forbidden address or no address at all, its own body limit was hit
     */
    case ClientRefused = 'client_refused';

    case TooManyRedirects = 'too_many_redirects';

    /** a redirect without a usable `Location` */
    case InvalidRedirect = 'invalid_redirect';

    case BudgetExceeded = 'budget_exceeded';

    /** a status that is neither a followable redirect nor 2xx */
    case HttpStatus = 'http_status';

    /** the response is not of the media type asked for (HTML for pages, JSON for APIs, the listed types for media) */
    case UnexpectedContentType = 'unexpected_content_type';

    /** the client's own body limit or `maxBytes` was passed (a pinning client's limit included) */
    case TooLarge = 'too_large';

    /** {@see Fetcher::fetchMedia()} only: a 2xx answer with an empty body */
    case EmptyBody = 'empty_body';
}
