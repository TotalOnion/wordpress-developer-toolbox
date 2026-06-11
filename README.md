# What is it?

CLI toolbox for WP devs

## Help

`wp help onionbox`

### Redirection Audit

`wp onionbox redirection-audit`

Audit http redirects from the Redirection plugin to check for 404's, loops etc

```
  wp onionbox redirection-audit [--module=<wordpress|apache|nginx|all>] [--max-redirects=<count>] [--max-age=<days>] [--verbose] [--ids=<id>...] [--match-url=<url>]

  [--module=<wordpress|apache|nginx|all>]
    Which module to test. Defaults to 'all'

  [--max-redirects=<count>]
    How many redirects to follow before giving up. Defaults to 5.

  [--max-age=<days>]
    How many days since a redirect was hit is considered "old". Defaults to 365

  [--verbose]
    Show passes as well as failures, and extra info in general.

  [--ids=<id>...]
    Array of redirect IDs to test. Useful for retesting a subset from an earlier full audit

  [--match-url=<url>]
    Check a single match-url. Copy and paste this into quotes from the Redirection page in wp-admin
```