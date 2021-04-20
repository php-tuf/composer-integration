<?php

namespace Tuf\ComposerIntegration;

use Psr\Http\Message\StreamInterface;
use Tuf\Client\Updater as BaseUpdater;
use Tuf\Metadata\TargetsMetadata;

/**
 * Defines an updater that exposes additional information about TUF targets.
 *
 * @internal
 */
class Updater extends BaseUpdater
{
    /**
     * {@inheritDoc}
     */
    public function verify(string $target, StreamInterface $data): void
    {
        parent::verify($target, $data);
    }

    /**
     * Returns the expected size of a target, in bytes.
     *
     * @param string $target
     *   The target path, as known to TUF.
     *
     * @return int
     *   The expected size of the target, in bytes. If not known, a maximum
     *   number of bytes is returned.
     */
    public function getLength(string $target): int
    {
        $this->refresh();

        // @todo Handle the possibility that the target's metadata might not be
        // in targets.json.
        // @see https://github.com/php-tuf/php-tuf/issues/116
        $targetsMetadata = TargetsMetadata::createFromJson($this->durableStorage['targets.json']);

        return $targetsMetadata->getLength($target) ?? static::MAXIMUM_DOWNLOAD_BYTES;
    }
}
