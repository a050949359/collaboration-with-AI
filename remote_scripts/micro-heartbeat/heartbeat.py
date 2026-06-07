#!/usr/bin/env python3
"""
Proxmox micro-host heartbeat script (one-shot, run via crontab).

Reads VM/CT status via pvesh (no API token needed when run as root),
writes a JSON payload to Redis with TTL, then exits.

Required env vars (or edit defaults below):
  REDIS_HOST      ZeroTier IP of the project host
  REDIS_PORT      default 6379
  REDIS_PASSWORD  leave empty if no auth
  REDIS_KEY       default micro:online
  REDIS_TTL       seconds, default 120
  PVE_NODE        Proxmox node name, default pve
"""

import json
import os
import socket
import subprocess
import sys
from datetime import datetime, timezone

try:
    import redis
except ModuleNotFoundError:
    print("ERROR: 'redis' package not found. Run: pip3 install redis", file=sys.stderr)
    sys.exit(1)

REDIS_HOST = os.environ.get("REDIS_HOST", "")
REDIS_PORT = int(os.environ.get("REDIS_PORT", 6379))
REDIS_PASSWORD = os.environ.get("REDIS_PASSWORD", "") or None
REDIS_KEY = os.environ.get("REDIS_KEY", "micro:online")
REDIS_TTL = int(os.environ.get("REDIS_TTL", 120))
PVE_NODE = os.environ.get("PVE_NODE", "pve")


def pvesh(path: str) -> list:
    try:
        r = subprocess.run(
            ["pvesh", "get", path, "--output-format", "json"],
            capture_output=True,
            text=True,
            timeout=10,
        )
        if r.returncode != 0:
            print(f"[warn] pvesh {path}: {r.stderr.strip()}", file=sys.stderr)
            return []
        data = json.loads(r.stdout)
        return data if isinstance(data, list) else []
    except Exception as e:
        print(f"[warn] pvesh {path} failed: {e}", file=sys.stderr)
        return []


def get_vms() -> list:
    items = pvesh(f"/nodes/{PVE_NODE}/qemu")
    result = []
    for item in items:
        vmid = item.get("vmid")
        if vmid is None:
            continue
        try:
            result.append({
                "id": int(vmid),
                "name": item.get("name", f"vm-{vmid}"),
                "type": "qemu",
                "status": item.get("status", "unknown"),
            })
        except (ValueError, TypeError):
            pass
    return result


def get_cts() -> list:
    items = pvesh(f"/nodes/{PVE_NODE}/lxc")
    result = []
    for item in items:
        vmid = item.get("vmid")
        if vmid is None:
            continue
        try:
            result.append({
                "id": int(vmid),
                "name": item.get("name", f"ct-{vmid}"),
                "type": "lxc",
                "status": item.get("status", "unknown"),
            })
        except (ValueError, TypeError):
            pass
    return result


def main():
    if not REDIS_HOST:
        print("ERROR: REDIS_HOST is not set.", file=sys.stderr)
        sys.exit(1)

    payload = {
        "host": socket.gethostname(),
        "last_seen": datetime.now(timezone.utc).isoformat(),
        "vms": get_vms(),
        "cts": get_cts(),
    }

    r = redis.Redis(
        host=REDIS_HOST,
        port=REDIS_PORT,
        password=REDIS_PASSWORD,
        socket_connect_timeout=5,
        socket_timeout=5,
        decode_responses=True,
    )
    r.set(REDIS_KEY, json.dumps(payload), ex=REDIS_TTL)
    print(f"[ok] {payload['last_seen']}  vms={len(payload['vms'])}  cts={len(payload['cts'])}")


if __name__ == "__main__":
    main()
