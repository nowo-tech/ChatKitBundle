# Engram

Repository-local **product spec** and **`REQ-*`** traceability (Makefiles, demos) are described in [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md).

This repository is prepared to work with Engram MCP in Cursor.

## MCP configuration

The repository-level MCP config lives at:

- `.cursor/mcp.json`

It defines:

- server: `engram`
- command: `engram`
- args: `["mcp"]`

## Suggested usage

- Use Engram to inspect project memory/context while implementing changes.
- Keep this file updated if MCP server configuration changes.
- For functional `REQ-*` anchors in this bundle, prefer [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md) over duplicating checklist prose here.
