#!/bin/bash
PS_VERSION=$1

set -e

# Docker images prestashop/prestashop may be used, even if the shop remains uninstalled
echo "Pull PrestaShop files (Tag ${PS_VERSION})"

docker rm -f temp-ps || true
docker volume rm -f ps-volume || true

# Create container without starting it - we just need it for volume sharing
docker create -v ps-volume:/var/www/html --name temp-ps prestashop/prestashop:$PS_VERSION

# Start container to populate the volume, wait for it to be ready, then stop it
docker start temp-ps
# Wait for container to be running and volume to be populated
timeout=30
while [ $timeout -gt 0 ]; do
    if docker ps --format '{{.Names}}' | grep -q '^temp-ps$'; then
        sleep 2
        docker stop temp-ps || true
        break
    fi
    sleep 1
    timeout=$((timeout-1))
done

# Ensure container exists (even if stopped) for --volumes-from
if ! docker ps -a --format '{{.Names}}' | grep -q '^temp-ps$'; then
    echo "Error: Container temp-ps was not created properly"
    exit 1
fi

# Run a container for PHPStan, having access to the module content and PrestaShop sources.
# This tool is outside the composer.json because of the compatibility with PHP 5.6
echo "Run PHPStan using phpstan-${PS_VERSION}.neon file"

docker run --rm --volumes-from temp-ps \
       -v $PWD:/var/www/html/modules/meilisearchprestashop \
       -e _PS_ROOT_DIR_=/var/www/html \
       --workdir=/var/www/html/modules/meilisearchprestashop phpstan/phpstan:0.12.54 \
       analyse \
       --configuration=/var/www/html/modules/meilisearchprestashop/tests/phpstan/phpstan-$PS_VERSION.neon

# Cleanup
docker rm -f temp-ps || true
