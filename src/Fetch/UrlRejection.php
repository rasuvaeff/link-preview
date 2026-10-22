<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Fetch;

/**
 * Why {@see UrlPolicy} turned a URL down.
 *
 * @api
 */
enum UrlRejection: string
{
    case Malformed = 'malformed';
    case Scheme = 'scheme';
    case Credentials = 'credentials';
    case MissingHost = 'missing_host';
    case InvalidHost = 'invalid_host';
    case NonCanonicalIp = 'non_canonical_ip';
    case Port = 'port';
    case PrivateAddress = 'private_address';
}
