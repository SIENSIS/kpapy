# kpapy

Automatic REST API generator for **CodeIgniter 4**, in the style of
[kpcrudy](https://github.com/SIENSIS/kpcrudy) · Generador automàtic d'API REST per a
**CodeIgniter 4**, a l'estil de kpcrudy.

> **Status:** specification phase. The requirements are in
> [docs/requirements.md](docs/requirements.md); development starts once they are validated.

Describe a resource (table or model, fields, relations, filters, permissions) and kpapy serves a
secure, documented JSON API for it: pagination, filters, search, sorting, custom operations,
masked ids compatible with kpcrudy, a central permission resolver, Shield access tokens with
scopes, login for apps with two-factor authentication, and OpenAPI 3.1 documentation.
