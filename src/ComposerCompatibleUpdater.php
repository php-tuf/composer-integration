<?php

namespace Tuf\ComposerIntegration;

use Psr\Http\Message\StreamInterface;
use Tuf\Client\Updater;
use Tuf\Metadata\TargetsMetadata;

/**
 * Defines an updater that exposes additional information about TUF targets.
 *
 * This class is necessary because, although PHP-TUF normally is responsible for downloading
 * and verifying its targets transparently (using its ::download() method), Composer doesn't
 * really support overriding its HttpDownloader service -- at least, not without some seriously
 * complicated and fragile sorcery. Therefore, this class exists specifically to allow
 * Composer to download its packages and metadata how it wants, while still allowing us to
 * verify and protect those downloads using PHP-TUF.
 *
 * This class should not be used or extended at any time, in any way, by external code. No
 * promise of backwards compatibility is made. You have been warned!
 *
 * @internal
 */
class ComposerCompatibleUpdater extends Updater
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
        // in targets.json, once https://github.com/php-tuf/php-tuf/pull/141 is
        // merged.
        // @see https://github.com/php-tuf/php-tuf/issues/116
        return TargetsMetadata::createFromJson($this->durableStorage['targets.json'])
            ->getLength($target);
    }
}
