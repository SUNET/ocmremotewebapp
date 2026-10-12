# OCM Remote WebApp

A nextcloud app to provide access to remote OCM webapp shares.

## Federation unit tests

These tests use Nextcloud's public APIs without booting a server or requiring a database.
Install the Composer bin dependencies, set `NEXTCLOUD_SERVER_ROOT` to a Nextcloud
checkout with its `3rdparty` dependencies installed, and run `composer test:federation`.
When the app is installed under Nextcloud's `apps/` directory, the server root is
detected automatically.

## Release packaging

`make package` builds an unsigned archive. `make sign`, `make appstore`, and
`make release` use the same package contents before signing.

The archive contains only `appinfo/info.xml`, `appinfo/routes.php`, `lib/`,
`templates/`, `img/`, compiled `js/` and `css/` assets (including asset licences),
and `LICENSE`, `CHANGELOG.md`, `README.md`, and `openapi.json`. The app has no
external PHP runtime dependencies, so Composer dependencies and manifests are
not shipped. Development tools, tests, frontend sources, build configuration,
caches, repository metadata, and existing source-tree signatures are excluded.
Packaging fails if required runtime paths are missing or staging contains symbolic links.
Add new runtime directories explicitly to the Makefile's package allowlist.

Run `composer test:package` to check archive contents and release-target wiring
using temporary fixtures without building the frontend, running Docker, or signing.
