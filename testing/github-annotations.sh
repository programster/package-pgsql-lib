#!/bin/bash

# Writes GitHub Actions annotations for a test run, which (unlike the logs) can be read without logging in to GitHub:
# a notice with the results of each PostgreSQL version (and of PHPStan), and errors listing the failed tests (grouped
# into at most 9, as a step can only show 10 error annotations), or the last lines of output if it failed without
# listing any (e.g. a fatal error, or PHPStan finding errors). Works with the output of both testing/main.php and
# testing/run-all-versions.sh.
# Usage: testing/github-annotations.sh <title> <file with the test output> <the tests' exit code>

set -u

TITLE="$1"
OUTPUT_FILE="$2"
STATUS="$3"
WORK_DIR="$(mktemp -d)"
ESCAPE="$(printf '\033')"


# Print stdin as a single annotation message: GitHub reads %0A as a new line, so % itself must be escaped first.
encodeForAnnotation() {
    sed 's/%/%25/g' | awk '{printf "%s%%0A", $0}'
}


# Remove the colours, and shorten each test's path to just its name.
sed -e "s/${ESCAPE}\[[0-9;]*m//g" -e 's#[^ ]*/testing/tests/##' -e 's#\.php: #: #' "${OUTPUT_FILE}" \
    > "${WORK_DIR}/clean.txt"

RESULTS="$(grep -E "^Testing against PostgreSQL|passed, [0-9]+ failed|^(Passed|Failed) on PostgreSQL|^PHPStan: " \
    "${WORK_DIR}/clean.txt" | encodeForAnnotation)"

echo "::notice title=${TITLE} results::${RESULTS:-no results were found}"

# Each test is listed twice (as it runs, and in the summary), so keep one of each, with its PostgreSQL version.
awk '/^Testing against PostgreSQL/ { version = "PostgreSQL " $4 ": " } /: FAILED - / { print version $0 }' \
    "${WORK_DIR}/clean.txt" | sort -u > "${WORK_DIR}/failures.txt"

TOTAL="$(wc -l < "${WORK_DIR}/failures.txt" | tr -d ' ')"

if [ "${TOTAL}" -gt 0 ]; then
    PER_GROUP="$(( (TOTAL + 8) / 9 ))"
    split -l "${PER_GROUP}" "${WORK_DIR}/failures.txt" "${WORK_DIR}/failure-group-"

    for GROUP in "${WORK_DIR}"/failure-group-*; do
        echo "::error title=${TITLE} failures::$(encodeForAnnotation < "${GROUP}")"
    done
elif [ "${STATUS}" -ne 0 ]; then
    echo "::error title=${TITLE} failed without listing a test::$(tail -n 30 "${WORK_DIR}/clean.txt" | encodeForAnnotation)"
fi

rm -rf "${WORK_DIR}"
