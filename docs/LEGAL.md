# Legal Notes

## Project Licence

The code in this repository (PHP, TypeScript, and configuration files authored for this project) is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**. The full licence text is in [`LICENSE`](../LICENSE).

---

## Third-Party Dependency Licences

### tldraw (production-restricted)

This app depends on the following packages published by tldraw, Inc.:

| Package | Version |
|---------|---------|
| `tldraw` | 4.x |
| `@tldraw/sync` | 4.x |
| `@tldraw/sync-core` | 4.x |
| `@tldraw/assets` | 4.x |

These packages are **not** distributed under an OSI-approved open-source licence. The [tldraw licence](https://github.com/tldraw/tldraw/blob/main/LICENSE.md) permits free use in development and evaluation environments. **Production deployment requires a separate commercial licence from tldraw, Inc.**

See [https://tldraw.dev/pricing](https://tldraw.dev/pricing) for current licensing terms.

#### Implications for operators

- Before deploying this app to a production Nextcloud instance, verify that you hold an appropriate tldraw licence.
- The AGPL-3.0 licence of this repository does not grant any rights to tldraw's code.
- Because tldraw's licence is not AGPL-3.0-compatible, this app **cannot currently be distributed through the Nextcloud App Store** without further clarification from tldraw, Inc. about open-source project exemptions.

#### Implications for contributors

Contributions to this repository must not introduce additional production-restricted or proprietary dependencies without explicit discussion in an issue first.

### All other dependencies

All remaining npm and PHP dependencies use permissive licences (MIT, Apache-2.0, BSD-2-Clause) that are compatible with AGPL-3.0.

| Scope | Licence |
|-------|---------|
| `react`, `react-dom` | MIT |
| `vite`, `typescript` | MIT / Apache-2.0 |
| `express`, `ws`, `cors`, `multer` | MIT |
| `express-rate-limit` | MIT |
| `dotenv` | BSD-2-Clause |

---

## File Format: `.tldr`

Drawings are stored as `.tldr` files. This format is defined by tldraw's internal serialisation and is not governed by a published open standard. Files cannot be opened by tooling other than tldraw's own libraries. Operators should factor this into their data retention and portability planning.

---

## AGPL-3.0 Obligations for Operators

If you run a modified version of this app on a server that users interact with over a network, AGPL-3.0 §13 requires you to make the corresponding source code available to those users. The simplest way to comply is to publish your fork publicly and link to it from the app's UI or documentation.

---

## Contact

For questions about licensing, open an issue at [https://github.com/larkly/nextcloud-tldraw/issues](https://github.com/larkly/nextcloud-tldraw/issues).
