<?php

namespace Tuf\ComposerIntegration;

use GuzzleHttp\Promise\PromiseInterface;
use Tuf\Client\GuzzleFileFetcher;

class FileFetcher extends GuzzleFileFetcher
{
    public $urlMap = [];

    public function fetchTarget(string $fileName, int $maxBytes, array $options = []): PromiseInterface
    {
        if (array_key_exists($fileName, $this->urlMap)) {
            return $this->fetchFile($this->urlMap[$fileName], $maxBytes, $options);
        }
        return parent::fetchTarget($fileName, $maxBytes, $options);
    }
}
