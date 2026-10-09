# Security Policy

## Reporting a vulnerability

Please do not report security vulnerabilities through public issues, pull requests, or discussions.

Report them privately through GitHub: open this repository's **Security** tab and choose **Report a vulnerability**. This creates a private advisory visible only to you and the maintainers.

Include what you can of:
- The CanastaBase version or image tag affected
- Steps to reproduce, or a proof of concept
- The impact you expect

## Supported versions

Security fixes are made to the latest CanastaBase release. Please confirm the issue on the latest release before reporting.

## Scope

This policy covers the CanastaBase image:
- The Dockerfile and the image build
- The startup, maintenance, and helper scripts in `_sources/scripts`
- The Apache, PHP, and PHP-FPM configuration in `_sources/configs`
- How the image installs and lays out MediaWiki core, extensions, and skins

Report these elsewhere:
- Vulnerabilities in MediaWiki core or Wikimedia-maintained extensions and skins: https://www.mediawiki.org/wiki/Reporting_security_bugs
- Vulnerabilities in Debian packages, PHP, or other upstream dependencies: the upstream project
- Vulnerabilities in the extensions and skins bundled by the Canasta image: https://github.com/CanastaWiki/Canasta
- Vulnerabilities in the Canasta CLI: https://github.com/CanastaWiki/Canasta-CLI

If you are unsure where an issue belongs, report it here and we will help route it.
