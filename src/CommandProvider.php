<?php

namespace Tuf\ComposerIntegration;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability
{
    /**
     * {@inheritDoc}
     */
    public function getCommands()
    {
        return [
          new ProtectCommand(),
        ];
    }
}
