# extras/install-site

The clean starter content that goes into `swf-install-<version>.zip`, built by
`.github/workflows/release.yml`. These files sit on top of the framework code
to make a complete, empty site: a welcome home page, a 404 page, a two-item
menu, an archives page, three footer columns, a favicon and a robots.txt.

They are deliberately generic. The repository's own `pages/` holds the
documentation site, which is not what a new install should start with.

The `.keep` files exist only so the empty `pages/images/` and
`pages/posts/images/` folders survive zipping; they are harmless if left in
place.
