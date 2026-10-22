<?php

declare(strict_types=1);

use Rasuvaeff\LinkPreview\Fetch\UrlPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

$policy = new UrlPolicy();

foreach ([
    'https://example.com/article',
    'HTTPS://Example.COM:443/a#frag',
    'http://localhost./',
    'http://[::1]/',
    'http://[::7f00:1]/',
    'http://169.254.169.254/latest/meta-data/',
    'http://2130706433/',
    'http://user@127.0.0.1/',
    'https://example.com:6379/',
    'ftp://example.com/',
] as $url) {
    $result = $policy->check(url: $url);

    printf("%-45s %s\n", $url, $result->isOk() ? 'ok  ' . $result->unwrap() : 'ERR ' . $result->error()?->value);
}

$dev = new UrlPolicy(allowedNetworks: ['10.20.0.0/16']);
printf("%-45s %s\n", 'http://10.20.1.5/ (dev allowlist)', $dev->check(url: 'http://10.20.1.5/')->isOk() ? 'ok' : 'ERR');
printf("%-45s %s\n", 'http://10.30.1.5/ (dev allowlist)', $dev->check(url: 'http://10.30.1.5/')->isOk() ? 'ok' : 'ERR');
