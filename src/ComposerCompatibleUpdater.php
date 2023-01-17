<?php

namespace Tuf\ComposerIntegration;

use Psr\Http\Message\StreamInterface;
use Tuf\Client\Updater;
use Tuf\Exception\NotFoundException;

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
        // This method is overridden in order to make it public, so that the
        // plugin can validate the length and hashes of a downloaded TUF target
        // after Composer has used its own mechanisms to retrieve it.
        parent::verify($target, $data);
    }

    /**
     * Returns the expected size of a target, in bytes.
     *
     * @param string $target
     *   The target path, as known to TUF.
     *
     * @return int
     *   The expected size of the target, in bytes.
     *
     * @throws \Tuf\Exception\NotFoundException
     *   Thrown if the target is not known to TUF.
     */
    public function getLength(string $target): int
    {
        $this->refresh();

        $metadata = $this->getMetadataForTarget($target);
        if ($metadata) {
            // We need to add 1 to the maximum file size returned by TUF because
            // if we actually download the expected number of bytes, Composer
            // will mistakenly think we have exceeded the maximum size, and
            // throw an exception. The purpose of TUF confirming the file size
            // is to prevent infinite data attacks, but adding 1 byte to the
            // expected size won't undermine that.
            // @see https://theupdateframework.github.io/specification/v1.0.18/#file-formats-targets
            return $metadata->getLength($target) + 1;
        } else {
            throw new NotFoundException($target, 'Target');
        }
    }
}
