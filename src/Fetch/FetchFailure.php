<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * @api
 */
enum FetchFailure: string
{
    /** the URL, or a redirect target, was turned down by the {@see UrlPolicy} */
    case PolicyRejected = 'policy_rejected';
    case Network = 'network';
    case TooManyRedirects = 'too_many_redirects';
    /** a redirect without a usable `Location` */
    case InvalidRedirect = 'invalid_redirect';
    case BudgetExceeded = 'budget_exceeded';
    /** a status that is neither a followable redirect nor 2xx */
    case HttpStatus = 'http_status';
    case NotHtml = 'not_html';
    case TooLarge = 'too_large';
}
