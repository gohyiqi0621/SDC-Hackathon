---
name: PHP-FPM and Nginx on Replit
description: Writable log locations for the PHP-FPM/Nginx publishing runtime.
---

When this project runs PHP-FPM and Nginx on Replit, direct logging to `/dev/stderr`, `/dev/stdout`, or default `/var/log` and Nix-store paths can make server startup fail with permission errors. Use a writable per-process runtime directory and forward those logs to the managed process output.

**Why:** The Replit runtime refused both `/dev/stderr` and the default immutable Nix-store PHP-FPM log location during local startup checks.

**How to apply:** Preserve writable runtime paths in the PHP-FPM and Nginx launcher configuration; check the new process output for startup errors before changing production settings.
