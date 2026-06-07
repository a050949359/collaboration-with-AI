#!/usr/bin/env python3
"""
Proxmox micro-host heartbeat script.

Reads VM/CT status via pvesh (no API token needed when run as root),
then writes a JSON payload to Redis with TTL every HEARTBEAT_INTERVAL seconds.

Required env vars (or edit defaults below):
  REDIS_HOST         ZeroTier IP of the project host
  REDIS_PORT         default 6379
  REDIS_PASSWORD     leave empty if no auth
  REDIS_KEY          default micro:online
  REDIS_TTL          seconds, default 90
  PVE_NODE           Proxmox node name, default pve
  HEARTBEAT_INTERVAL seconds between writes, default 30
"""

import json
import os
import socket
import subprocess
import sys
import time
from datetime import datetime, timezone

REDIS_HOST = os.environ.get("REDIS_HOST", "")
REDIS_PORT = int(os.environ.get("REDIS_PORT", 6379))
REDIS_PASSWORD = os.environ.get("REDIS_PASSWORD", "") or None
REDIS_KEY = os.environ.get("REDIS_KEY", "micro:online")
REDIS_TTL = int(os.environ.get("REDIS_TTL", 90))
PVE_NODE = os.environ.get("PVE_NODE", "pve")
INTERVAL = int(os.environ.get("HEARTBEAT_INTERVAL", 30))


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
        return json.loads(r.stdout)
    except Exception as e:
        print(f"[warn] pvesh {path} failed: {e}", file=sys.stderr)
        return []


def get_vms() -> list:
    items = pvesh(f"/nodes/{PVE_NODE}/qemu")
    return [
        {
            "id": int(item["vmid"]),
            "name": item.get("name", f"vm-{item['vmid']}"),
            "type": "qemu",
            "status": item.get("status", "unknown"),
        }
        for item in items
    ]


def get_cts() -> list:
    items = pvesh(f"/nodes/{PVE_NODE}/lxc")
    return [
        {
            "id": int(item["vmid"]),
            "name": item.get("name", f"ct-{item['vmid']}"),
            "type": "lxc",
            "status": item.get("status", "unknown"),
        }
        for item in items
    ]


def make_payload() -> dict:
    return {
        "host": socket.gethostname(),
        "last_seen": datetime.now(timezone.utc).isoformat(),
        "vms": get_vms(),
        "cts": get_cts(),
    }


def connect_redis():
    import redis

    return redis.Redis(
        host=REDIS_HOST,
        port=REDIS_PORT,
        password=REDIS_PASSWORD,
        socket_connect_timeout=5,
        socket_timeout=5,
        decode_responses=True,
    )


def main():
    if not REDIS_HOST:
        print("ERROR: REDIS_HOST is not set.", file=sys.stderr)
        sys.exit(1)

    print(f"[info] starting heartbeat → redis://{REDIS_HOST}:{REDIS_PORT}/{REDIS_KEY} (TTL={REDIS_TTL}s, interval={INTERVAL}s)")

    while True:
        try:
            r = connect_redis()
            payload = make_payload()
            r.set(REDIS_KEY, json.dumps(payload), ex=REDIS_TTL)
            print(f"[ok] {payload['last_seen']}  vms={len(payload['vms'])}  cts={len(payload['cts'])}")
        except Exception as e:
            print(f"[error] {e}", file=sys.stderr)

        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()
