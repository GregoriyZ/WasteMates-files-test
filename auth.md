# WasteMates auth.md

This document tells agents what authentication, if any, applies to WasteMates'
public API surface. See [/.well-known/api-catalog](/.well-known/api-catalog)
for the full list of what's discoverable, and [/openapi.json](/openapi.json) /
[/api-docs.html](/api-docs.html) for the API itself.

## Agent audience

This applies to any automated agent interacting with `wastemates.com.au` —
whether crawling for content (see the `text/markdown` negotiation described
in [/api-docs.html](/api-docs.html)) or calling the enquiry API directly,
e.g. to submit a quote request on a visitor's behalf.

## Authentication: none

WasteMates has no OAuth server, no API keys, no accounts, and no credential
system of any kind. The one documented API, `POST /send-enquiry.php`, is
intentionally open to the same degree a browser submitting the on-site form
is — no token, header, or prior registration step is required or supported.

There is no OAuth Protected Resource Metadata or Authorization Server
Metadata to publish, because there is no OAuth-protected resource on this
site. If that changes, this file and the corresponding
`/.well-known/oauth-protected-resource` document will be published together.

## Registration / provisioning

Not applicable — there is no client registration endpoint, since there is
nothing to register a client credential against.

## Credential use

Not applicable — no credentials are issued, transmitted, or checked. Abuse
protection on the enquiry endpoint (honeypot field, server-side field
validation, upload size/type limits) does not depend on caller identity.
