#!/bin/bash

print_error() {
    echo "\033[1;31m$1\033[0m"
}

if [ "$#" -eq 1 ]; then
    # Check the CHANGELOG.md
    changelog_version=$(awk '/## /{print $2;exit}' CHANGELOG.md)

    if [ "$changelog_version" != "$1" ]; then
        print_error "Version in CHANGELOG.md does not match the release version."
        exit 1
    fi

    # Define the path to the Version.php file
    php_version_file_path="src/Core/Version.php"

    # Extract the version from the PHP file
    php_version=$(awk -F '=' '/const MAJOR/{major=$2} /const MINOR/{minor=$2} /const PATCH/{patch=$2} END{print major"."minor"."patch}' "$php_version_file_path")

    # Remove semicolons using tr
    php_version=$(echo "$php_version" | tr -d ';')

    # Compare the desired version with the PHP version
    if [ "$1" != "$php_version" ]; then
        print_error "Version mismatch: Desired version $1, Version.php is $php_version"
        exit 1
    fi

    echo "Creating tag for release version $1"

    git tag "v$1"

    if [ $? -eq 0 ]; then
        echo "Git tag created successfully for v$1."
        # Push the tag to the origin
        git push origin --tags

        if [ $? -eq 0 ]; then
            echo "Git push successful. New release v$1 is available"
        else
            print_error "Git push failed."
            exit 1
        fi
    else
        print_error "Git tag creation failed."
        exit 1
    fi
fi