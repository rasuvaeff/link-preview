<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\LinkPreview\Fetch\FetchFailureReason;

/**
 * What went wrong with a preview; {@see PreviewFailure} carries it.
 *
 * @api
 */
enum PreviewFailureReason: string
{
    /** the URL (or the `pageUrl` of `fromHtml()`, or a redirect target) was turned down by the URL policy */
    case PolicyRejected = 'policy_rejected';
    case Network = 'network';
    /** the PSR-18 client refused the request (a pinning client found a forbidden or no address, its own body limit) */
    case ClientRefused = 'client_refused';
    case TooManyRedirects = 'too_many_redirects';
    case InvalidRedirect = 'invalid_redirect';
    case BudgetExceeded = 'budget_exceeded';
    case HttpStatus = 'http_status';
    case UnexpectedContentType = 'unexpected_content_type';
    case TooLarge = 'too_large';
    /** the site's adapter needs an access token that was not configured, and the page itself was unavailable */
    case MissingToken = 'missing_token';
    /** the page was fetched but no extractor found anything in it */
    case EmptyPage = 'empty_page';

    public static function fromFetch(FetchFailureReason $reason): self
    {
        return match ($reason) {
            FetchFailureReason::PolicyRejected => self::PolicyRejected,
            FetchFailureReason::Network => self::Network,
            FetchFailureReason::ClientRefused => self::ClientRefused,
            FetchFailureReason::TooManyRedirects => self::TooManyRedirects,
            FetchFailureReason::InvalidRedirect => self::InvalidRedirect,
            FetchFailureReason::BudgetExceeded => self::BudgetExceeded,
            FetchFailureReason::HttpStatus => self::HttpStatus,
            FetchFailureReason::UnexpectedContentType => self::UnexpectedContentType,
            FetchFailureReason::TooLarge => self::TooLarge,
        };
    }
}
