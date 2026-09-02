# Composer distribution mirrors

Packagist requires one VCS repository per Composer package. The eight package
repositories are generated distribution mirrors; `AtomsPHP/atoms` remains the
only source repository and issue tracker.

| Package | Mirror |
|---|---|
| `atoms/core` | `AtomsPHP/core` |
| `atoms/client` | `AtomsPHP/client` |
| `atoms/laravel` | `AtomsPHP/laravel` |
| `atoms/symfony` | `AtomsPHP/symfony` |
| `atoms/testing` | `AtomsPHP/testing` |
| `atoms/phpstan-rules` | `AtomsPHP/phpstan-rules` |
| `atoms/cli` | `AtomsPHP/cli` |
| `atoms/database-illuminate` | `AtomsPHP/database-illuminate` |

For every mirror:

- disable issues, pull requests, projects, discussions, and the wiki;
- protect `main` against deletion and force pushes;
- allow only the `atoms-release` GitHub App to write or bypass protection;
- do not grant that App contents-write access to the monorepo;
- keep the generated README's support links pointed at the monorepo;
- never edit or tag the mirror by hand.

The release workflow checks out the monorepo with its repository-scoped
`GITHUB_TOKEN`. It separately mints an installation token restricted to the
eight mirrors, derives each mirror commit with `git subtree split`, and pushes
the synchronized branch and immutable package tag through that token.

Before public launch, run the release workflow's `seed-mirrors` dispatch while
the release manifest is still `candidate`. That path updates private mirror
`main` branches only and cannot publish npm, create a GitHub release, or push
a package tag.
