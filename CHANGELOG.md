# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.10] - 2026-10-12

### Fixed

- Pass folder shares without a webapp protocol unchanged to the existing federation provider, regardless of resource types in the remaining multi-protocol entries.
- Preserve ordinary folder-share validation, group sharing, and signed notification handling while retaining webapp validation and authenticated unshare handling.
- Parse multi-digit version components correctly when naming release archives.
- Package only runtime files and public release documentation, excluding development dependencies, tooling, frontend sources, caches, and stale source signatures.

## [1.0.9] - 2026-10-09

### Added

- First GitHub release of OCM Remote WebApp, supporting remote web app shares in embedded views, new tabs, and the current tab.

### Changed

- Update frontend dependencies, including Vue, Nextcloud components, Axios, and DOMPurify.

### Fixed

- Repair the npm lockfile for reproducible dependency installation.
- Fix Vue template formatting and the JSDoc parameter annotation.
