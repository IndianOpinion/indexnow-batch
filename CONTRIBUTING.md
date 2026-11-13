# Contributing

Thanks for helping. Bug reports, fixes and small focused features are all
welcome. Please open an issue before a large change so we can agree the
shape first.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/indexnow-batch.git
cd indexnow-batch
composer install
```

No WordPress install is needed: the tests run against a small stub layer
in `tests/stubs/`.

## Tests and checks

```sh
composer test    # PHPUnit 10
composer stan    # PHPStan level 8
composer cs      # PHP_CodeSniffer, PSR-12
composer check   # all three
```

CI runs the same on PHP 8.1, 8.2 and 8.3. Write 8.1-compatible code: no
readonly classes, no DNF types, no `true` type, no constants in traits.

Every behaviour in `docs/PRD.md` section 4 has a test. Name acceptance
tests after their PRD number (`testT21...`) and assert behaviour, not just
that code runs. If you add a stub function, keep it small and faithful to
WordPress.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Typed exceptions, no silent failure, no `@` suppression, no `eval`, no shell.
- Escape every output on admin pages; nonce and capability checks on every action.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request, with tests.
- Update `CHANGELOG.md` under Unreleased, and `README.md` when behaviour or options change.
- Record design decisions in `docs/ARCHITECTURE.md`.
- `composer check` must pass.
- A DCO-style sign-off is not required.

By contributing you agree your work is released under the MIT licence.
