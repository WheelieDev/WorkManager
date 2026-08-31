---
paths:
  - openapi.yaml
---

# General

## Keep the OpenAPI spec exact and DRY
openapi.yaml describes routes/api.php and must match the controllers exactly: every status code, message string, nullable field, and validation message comes from the code and its tests, not from memory. Define shared shapes once under components (schemas, responses, examples) and $ref them; never repeat an inline envelope or error body. Update the spec in the same change as any API edit, and lint it (npx @redocly/cli lint openapi.yaml) before finishing.
