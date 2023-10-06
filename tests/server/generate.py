# For instructions on using this script, please see the README.

from tuf import repository_tool as rt
import os
import shutil

from os import path

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
    (package_metadata_public, package_metadata_private) = import_keypair('package_metadata')
    (package_public, package_private) = import_keypair('package')

    # Assign the keys to their roles.
    repository.root.add_verification_key(root_public)
    repository.root.load_signing_key(root_private)
    repository.targets.add_verification_key(targets_public)
    repository.targets.load_signing_key(targets_private)
    repository.snapshot.add_verification_key(snapshot_public)
    repository.snapshot.load_signing_key(snapshot_private)
    repository.timestamp.add_verification_key(timestamp_public)
    repository.timestamp.load_signing_key(timestamp_private)

    # Create delegated roles and add their keys.
    repository.targets.delegate('package_metadata', [package_metadata_public], ['files/packages/8/p2/*'])
    repository.targets('package_metadata').load_signing_key(package_metadata_private)
    repository.targets.delegate('package', [package_public], ['drupal/*'])
    repository.targets('package').load_signing_key(package_private)

    repository.mark_dirty(['root', 'snapshot', 'targets', 'timestamp'])

    # Add more targets here as needed.
    repository.targets.add_targets(['packages.json'])
    repository.targets('package_metadata').add_target('files/packages/8/p2/drupal/token.json')
    repository.targets('package').add_target('drupal/token/1.9.0.0')

    # Write and publish the repository.
    repository.mark_dirty(['snapshot', 'targets', 'timestamp', 'package_metadata', 'package'])
    repository.status()
    repository.writeall(consistent_snapshot=True)

    staging_dir = path.join(dir, 'metadata.staged')
    live_dir = path.join(dir, 'metadata')
    if path.exists(live_dir):
        os.rmdir(live_dir)
    os.rename(staging_dir, live_dir)

    # Uncomment this line to generate the client-side metadata. This
    # will probably not normally be needed, but it's here for reference.
    # rt.create_tuf_client_directory(dir, path.join(dir, 'tufclient'))

generate_fixture()
