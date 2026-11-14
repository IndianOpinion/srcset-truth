# Contributing

Thank you for helping. This file says how to set up, test and send a change.

## Setup

You need PHP 8.1 or later with GD, Imagick (optional, for the full encoder
tests) and Composer.

```sh
git clone https://github.com/IndianOpinion/srcset-truth.git
cd srcset-truth
composer install
```

No WordPress install is needed. Tests use a small stub layer in
`tests/stubs/` and the real WordPress HTML tag processor from the
`johnpbloch/wordpress-core` dev dependency.

## Tests and checks

```sh
composer test    # PHPUnit
composer stan    # PHPStan, level 8
composer cs      # phpcs, PSR-12
composer check   # all three
```

Encoder tests skip with a message when Imagick or GD with WebP is missing.
CI runs on PHP 8.1, 8.2 and 8.3; write 8.1-compatible code (no readonly
classes, no DNF types, no `true` type, no constants in traits).

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file under `src/`.
- Typed exceptions, never silent failure, never the `@` operator.
- No `eval`, no shell. Escape every output; validate every input.
- Every default is a filter. Add new filters to the README table.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- One change per pull request, with tests. Name the PRD case it touches if
  there is one.
- Update `CHANGELOG.md` under Unreleased.
- Keep `main` green: tests, PHPStan and phpcs must pass.
- A sign-off line is not required.
