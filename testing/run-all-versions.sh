#!/bin/bash

# Runs the test suite against every supported major version of PostgreSQL, each in its own throwaway docker
# container, which keeps its data in memory and is removed afterwards, and then runs PHPStan (see phpstan.neon.dist).
# Usage: testing/run-all-versions.sh [version ...], e.g. testing/run-all-versions.sh 16 17. Defaults to all of them.
# Exits non-zero if any version's tests fail, or PHPStan finds any errors.

set -u

# PostgreSQL's supported major versions, see https://www.postgresql.org/support/versioning/
DEFAULT_VERSIONS=(14 15 16 17 18)
TESTING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$#" -gt 0 ]; then
    VERSIONS=("$@")
else
    VERSIONS=("${DEFAULT_VERSIONS[@]}")
fi

export TEST_DB_HOST=127.0.0.1
export TEST_DB_USER=test
export TEST_DB_PASSWORD=test
export TEST_DB_NAME=pgsql_lib_test

# Stop Git Bash on Windows rewriting arguments that look like paths, e.g. turning the container's /var/lib/postgresql
# into C:/Program Files/Git/var/lib/postgresql. Other systems ignore it.
export MSYS_NO_PATHCONV=1


# Remove the given container, if it exists.
removeContainer() {
    docker rm --force "$1" > /dev/null 2>&1
}


# Wait up to a minute for the given container's database to accept queries. Returns non-zero if it doesn't.
# pg_isready alone isn't enough, as the image's entrypoint restarts the server once it has set up the database.
waitForDatabase() {
    local containerName="$1"
    local attempts=0
    local ready=1

    while [ "${ready}" -ne 0 ] && [ "${attempts}" -lt 60 ]; do
        if docker exec "${containerName}" psql -U "${TEST_DB_USER}" -d "${TEST_DB_NAME}" -h 127.0.0.1 -c 'SELECT 1' \
            > /dev/null 2>&1
        then
            ready=0
        else
            attempts="$((attempts + 1))"
            sleep 1
        fi
    done

    return "${ready}"
}


# Run the tests against the given major version, in a new container. Returns non-zero if they fail.
testVersion() {
    local version="$1"
    local containerName="pgsql-lib-test-pg${version}"
    local port="$((5460 + version))" # e.g. 5478 for PostgreSQL 18, away from the usual 5432 and 5433.
    local exitCode=0

    removeContainer "${containerName}"

    if ! docker run --detach --rm \
        --name "${containerName}" \
        --env POSTGRES_USER="${TEST_DB_USER}" \
        --env POSTGRES_PASSWORD="${TEST_DB_PASSWORD}" \
        --env POSTGRES_DB="${TEST_DB_NAME}" \
        --tmpfs /var/lib/postgresql \
        --publish "127.0.0.1:${port}:5432" \
        "postgres:${version}" > /dev/null
    then
        echo "Failed to start a PostgreSQL ${version} container."
        exitCode=1
    elif ! waitForDatabase "${containerName}"; then
        echo "PostgreSQL ${version} didn't start within a minute."
        exitCode=1
    elif ! TEST_DB_PORT="${port}" php "${TESTING_DIR}/main.php"; then
        exitCode=1
    fi

    removeContainer "${containerName}"
    return "${exitCode}"
}


FAILED_VERSIONS=()

for VERSION in "${VERSIONS[@]}"; do
    echo "=== PostgreSQL ${VERSION} ==="

    if ! testVersion "${VERSION}"; then
        FAILED_VERSIONS+=("${VERSION}")
    fi

    echo
done

echo "=== PHPStan ==="
PHPSTAN_FAILED=0

if ! php "${TESTING_DIR}/../vendor/bin/phpstan" analyse --configuration="${TESTING_DIR}/../phpstan.neon.dist" \
    --no-progress --memory-limit=1G
then
    PHPSTAN_FAILED=1
fi

echo

if [ "${#FAILED_VERSIONS[@]}" -gt 0 ]; then
    echo "Failed on PostgreSQL: ${FAILED_VERSIONS[*]}"
else
    echo "Passed on PostgreSQL: ${VERSIONS[*]}"
fi

if [ "${PHPSTAN_FAILED}" -eq 1 ]; then
    echo "PHPStan: FAILED"
else
    echo "PHPStan: passed"
fi

if [ "${#FAILED_VERSIONS[@]}" -gt 0 ] || [ "${PHPSTAN_FAILED}" -eq 1 ]; then
    exit 1
fi
