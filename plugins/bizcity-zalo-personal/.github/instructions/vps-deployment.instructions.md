---
name: zalo-personal-vps-deployment-local
description: "Use when this bizcity-zalo-personal plugin is opened alone and you need VPS Docker deployment, bridge_pg preservation, source-to-dist build, or overlay2 recovery guidance."
applyTo: "**/*"
---

# Zalo Personal Deployment Boundary

Use the canonical deployment rules from the parent workspace instruction:
`../../.github/instructions/zalo-personal-vps-deployment.instructions.md`.

The non-negotiable invariants are:

- Deploy from `/home/vibeyeuc/zca-bridge`.
- Runtime is `node dist/main.js`; source changes must be compiled into a new Docker image.
- Rebuild/recreate only `zca-bridge`; preserve `bridge-db`, database `zca`, and named volume `bridge_pg`.
- Never use `docker compose down -v`, `docker system prune -a --volumes`, `docker volume rm bridge_pg`, or manual `/var/lib/docker/overlay2` deletion.
- For `overlay2 ... device or resource busy`, reboot once, inspect exact `Dead`/`Created` bridge containers, and remove only the exact stale bridge container.
- Use `{{.Label "com.docker.compose.service"}}` in `docker ps --format`, not `{{index .Labels "com.docker.compose.service"}}`; `.Labels` is rendered as a string. Never type an example container ID literally; obtain and inspect the real stale `zca-bridge` ID first.
- Use `bridge-db` as the Compose database hostname and verify `pg_isready` before starting the bridge.
- Do not upload or print `.env`, secrets, credentials, raw Zalo IDs, message content, or cookies.

Canonical detailed procedure: `../_library/zca-bridge-main/VPS-DEPLOYMENT-RUNBOOK.md`.
