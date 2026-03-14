# ADR-001: MySQL over SQLite for local development

**Date:** 2026-03-12
**Status:** Accepted

## Context
Needed a local database for the AsyncOps assessment project. The two practical options were SQLite (zero-setup, file-based) and MySQL (requires a running server).

## Decision
MySQL 8 via Docker (`asyncops-mysql` container, port 3306, password `secret`).

## Consequences
- Full MySQL feature set available (JSON columns, strict mode, full-text search) — matches a realistic production environment
- Queue jobs that write to the DB concurrently behave identically to prod
- Requires the Docker container to be running: `docker start asyncops-mysql`
- SQLite dialect differences (case sensitivity, type affinity) cannot mask bugs

## Source
Session: [2026-03-12-001-project-init](../sessions/2026-03-12-001-project-init.md)
