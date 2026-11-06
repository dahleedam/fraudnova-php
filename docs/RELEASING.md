# Releasing fraudnova-php

These are your steps to publish the package. The code, tests and docs are already committed on `main`.

## First release (1.0.0)

1. Create the empty public repository `dahleedam/fraudnova-php` on GitHub (no README, no licence, no .gitignore).
2. Push `main`:

   ```bash
   git remote add origin git@github.com:dahleedam/fraudnova-php.git
   git push -u origin main
   ```

3. Open https://github.com/dahleedam/fraudnova-php/actions and wait for the **CI** run to go green on every PHP version (8.2 to 8.5, plus 8.2 with the lowest dependencies). Do not tag until it is green.
4. Tag the release and push the tag:

   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

   The **Release** workflow creates the GitHub release with generated notes.
5. Submit the package on Packagist: sign in at https://packagist.org/packages/submit, enter `https://github.com/dahleedam/fraudnova-php` and click **Check**, then **Submit**.
6. Turn on automatic updates so Packagist picks up every new tag. Either connect your GitHub account in your Packagist profile (the GitHub integration then adds the hook for you), or add it by hand: on GitHub go to **Settings → Webhooks → Add webhook**, payload URL `https://packagist.org/api/github?username=<your Packagist username>`, content type `application/json`, secret = your Packagist API token (Packagist → your profile → **Show API Token**). On the package page at https://packagist.org/packages/fraudnova/fraudnova-php, the "not auto-updated" warning disappears once the hook works; click **Update** once to sync now.
7. Check that `composer require fraudnova/fraudnova-php` works in an empty directory.
8. Within a day, confirm the package appears on https://packalyst.com. It lists Laravel packages from Packagist automatically; there is nothing to submit.

## Follow-ups in the other repositories (separate session)

- Main app: add a "PHP SDK" install line (`composer require fraudnova/fraudnova-php guzzlehttp/guzzle`) with a link to the GitHub repository on `/docs`.
- Main app: add a changelog entry for the PHP SDK on `/admin/changelog`.
- Examples repository: link the SDK from the README of `dahleedam/fraudnova-examples`.

## Patch and minor releases

1. Make the change on `main` with tests.
2. Bump `Client::VERSION` in `src/Client.php` (it is sent in the `User-Agent` header).
3. In `CHANGELOG.md`, move the entries under `## [Unreleased]` into a new section such as `## [1.0.1] - YYYY-MM-DD`, and add its link reference at the bottom.
4. Commit, push, wait for CI to go green, then tag and push the tag:

   ```bash
   git tag v1.0.1
   git push origin v1.0.1
   ```

   Packagist updates through the hook; the Release workflow creates the GitHub release.

## Rules

Nothing public (README, changelog, docs, code, commit messages) may name the upstream data provider or describe keys, key pools or quota internals.

CI runs `composer validate --strict` and the leak-guard test (`tests/LeakGuardTest.php`) on every push. The leak guard only catches the provider's name or abbreviation in tracked files. It does not read commit messages, and it cannot tell when text describes keys, key pools or quota internals, so check those by hand before you push.

`tests/ReleaseConsistencyTest.php` fails when `Client::VERSION` differs from the newest version heading in `CHANGELOG.md`, so bump both together.
