<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Rasuvaeff\LinkPreview\Extract\HtmlFallbackExtractor;
use Rasuvaeff\LinkPreview\Extract\OpenGraphExtractor;
use Rasuvaeff\LinkPreview\Extract\TwitterCardsExtractor;
use Rasuvaeff\LinkPreview\Fetch\Fetcher;
use Rasuvaeff\LinkPreview\Limits;
use Rasuvaeff\LinkPreview\PreviewProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

$html = <<<'HTML'
    <title>   The   plain   title </title>
    <meta name="description" content="Plain description that is rather long and will be cut by the limit.">
    <meta property="og:title" content="OpenGraph title">
    <meta name="twitter:title" content="Twitter title">
    <meta name="twitter:image" content="/card.png">
    HTML;

$noNetwork = new class implements ClientInterface {
    public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        throw new LogicException('not used: the HTML is passed in');
    }
};
$fetcher = new Fetcher(client: $noNetwork, requestFactory: new HttpFactory());

$default = new PreviewProvider(fetcher: $fetcher, limits: new Limits(descriptionLength: 40));
$info = $default->fromHtml(html: $html, pageUrl: 'https://example.com/post')->unwrap();
echo "default priority (OpenGraph > Twitter > HTML):\n";
echo "  title:       $info->title\n  description: $info->description\n  image:       $info->image\n";

$reordered = new PreviewProvider(
    fetcher: $fetcher,
    extractors: [new HtmlFallbackExtractor(), new TwitterCardsExtractor(), new OpenGraphExtractor()],
);
echo "HTML first:\n  title:       ", $reordered->fromHtml(html: $html, pageUrl: 'https://example.com/post')->unwrap()->title, "\n";
