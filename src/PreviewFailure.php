<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview;

use Rasuvaeff\LinkPreview\Fetch\FetchFailure;

/**
 * Why {@see PreviewProvider} produced no preview.
 *
 * @api
 */
enum PreviewFailure: string
{
    case PolicyRejected = 'policy_rejected';
    case Network = 'network';
    case TooManyRedirects = 'too_many_redirects';
    case InvalidRedirect = 'invalid_redirect';
    case BudgetExceeded = 'budget_exceeded';
    case HttpStatus = 'http_status';
    case NotHtml = 'not_html';
    case TooLarge = 'too_large';
    /** the page was fetched but no extractor found anything in it */
    case EmptyPage = 'empty_page';

    public static function fromFetch(FetchFailure $failure): self
    {
        return match ($failure) {
            FetchFailure::PolicyRejected => self::PolicyRejected,
            FetchFailure::Network => self::Network,
            FetchFailure::TooManyRedirects => self::TooManyRedirects,
            FetchFailure::InvalidRedirect => self::InvalidRedirect,
            FetchFailure::BudgetExceeded => self::BudgetExceeded,
            FetchFailure::HttpStatus => self::HttpStatus,
            FetchFailure::NotHtml => self::NotHtml,
            FetchFailure::TooLarge => self::TooLarge,
        };
    }
}
