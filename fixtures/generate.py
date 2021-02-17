# For instructions on using this script, please see the README.

from tuf import repository_tool as rt
import os
import shutil

from os import path
from unittest import mock

# This file largely derives from:
# https://github.com/php-tuf/php-tuf/blob/main/generate_fixtures.py

def import_keypair(name):
    dir = path.join(os.getcwd(), 'keys')
    private_path = path.join(dir, name)
    public_path = private_path + '.pub'

    # Load the keys into TUF.
    public = rt.import_ed25519_publickey_from_file(public_path)
    private = rt.import_ed25519_privatekey_from_file(private_path, password='pw')

    return (public, private)

@mock.patch('time.time', mock.MagicMock(return_value=1577836800))
def generate_fixture():
    dir = os.getcwd()

    # Create a basic TUF repository.
    print('Initializing TUF repository in', dir)
    repository = rt.create_new_repository(dir)

    # Import key pairs for all required roles.
    (root_public, root_private) = import_keypair('root')
    (targets_public, targets_private) = import_keypair('targets')
    (snapshot_public, snapshot_private) = import_keypair('snapshot')
    (timestamp_public, timestamp_private) = import_keypair('timestamp')

    # Assign the keys to their roles.
    repository.root.add_verification_key(root_public)
    repository.root.load_signing_key(root_private)
    repository.targets.add_verification_key(targets_public)
    repository.targets.load_signing_key(targets_private)
    repository.snapshot.add_verification_key(snapshot_public)
    repository.snapshot.load_signing_key(snapshot_private)
    repository.timestamp.add_verification_key(timestamp_public)
    repository.timestamp.load_signing_key(timestamp_private)

    repository.mark_dirty(['root', 'snapshot', 'targets', 'timestamp'])

    # Add more targets here as needed.
    repository.targets.add_targets(['packages.json'])
    repository.targets.add_targets(['p2/drupal/core.json'])

    # Write and publish the repository.
    repository.mark_dirty(['snapshot', 'targets', 'timestamp'])
    repository.status()
    repository.writeall(consistent_snapshot=True)

    staging_dir = path.join(dir, 'metadata.staged')
    live_dir = path.join(dir, 'metadata')
    os.rename(staging_dir, live_dir)

    # Uncomment this line to generate the client-side metadata. This
    # will probably not normally be needed, but it's here for reference.
    # rt.create_tuf_client_directory(dir, path.join(dir, 'tufclient'))

generate_fixture()
