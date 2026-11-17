# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.1] - 2026-11-17

### Fixed

- Pin `@nextcloud/vue` to 9.13.1 and override `vue-router` to 5.3.1: vue-router >= 5.4 pulls in `ast-walker-scope` 0.10, whose engines require node >= 24.11 and broke `npm ci --engine-strict` (used by the release Makefile) on older node 24.x.

## [1.1.0] - 2026-11-17

### Changed

- Follow OCM-API v08 (cs3org/OCM-API#423): the `webapp` protocol payload's `appName` and `mediaTypes` fields are replaced by an `apps` array of `{name, mediaTypes}` entries. Inbound shares are parsed from `apps`, stored in a new `apps` column (existing `app_name`/`media_types` values are migrated into it and the old columns dropped), and exposed to the frontend as `apps`; the share title and launch pages use the first entry's `name`.
- Update composer and npm dependencies, and raise the development PHP requirement to `^8.2` (Nextcloud 31+ requires PHP 8.2, and current security advisories block PHP 8.1).

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
