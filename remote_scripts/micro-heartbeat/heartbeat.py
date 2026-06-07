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

def _get_int_env(key: str, default: int) -> int:
    try:
        return int(os.environ.get(key) or default)
    except ValueError:
        return default


REDIS_HOST = os.environ.get("REDIS_HOST", "")
REDIS_PORT = _get_int_env("REDIS_PORT", 6379)
REDIS_PASSWORD = os.environ.get("REDIS_PASSWORD", "") or None
REDIS_KEY = os.environ.get("REDIS_KEY", "micro:online")
REDIS_TTL = _get_int_env("REDIS_TTL", 120)
PVE_NODE = os.environ.get("PVE_NODE", "pve")

_FORMAT_ERROR = object()  # sentinel: pvesh returned unexpected structure


def pvesh(path: str):
    """
    Returns a list of dicts on success.
    Returns _FORMAT_ERROR if Proxmox response is not a list-of-dicts.
    Returns [] on command failure or empty result.
    """
    try:
        r = subprocess.run(
            ["pvesh", "get", path, "--output-format", "json"],
            capture_output=True,
            text=True,
            timeout=10,
        )
        if r.returncode != 0:
            print(f"[warn] pvesh {path}: {r.stderr.strip()}", file=sys.stderr)
            return _FORMAT_ERROR
        data = json.loads(r.stdout)
        if not isinstance(data, list):
            print(f"[warn] pvesh {path}: expected list, got {type(data).__name__}", file=sys.stderr)
            return _FORMAT_ERROR
        if data and not isinstance(data[0], dict):
            print(f"[warn] pvesh {path}: expected list of dicts, got list of {type(data[0]).__name__}", file=sys.stderr)
            return _FORMAT_ERROR
        return data
    except Exception as e:
        print(f"[warn] pvesh {path} failed: {e}", file=sys.stderr)
        return _FORMAT_ERROR


def get_vms() -> tuple[list, bool]:
    """Returns (vm_list, had_format_error)."""
    raw = pvesh(f"/nodes/{PVE_NODE}/qemu")
    if raw is _FORMAT_ERROR:
        return [], True
    result = []
    for item in raw:
        if not isinstance(item, dict):
            return [], True
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
    return result, False


def get_cts() -> tuple[list, bool]:
    """Returns (ct_list, had_format_error)."""
    raw = pvesh(f"/nodes/{PVE_NODE}/lxc")
    if raw is _FORMAT_ERROR:
        return [], True
    result = []
    for item in raw:
        if not isinstance(item, dict):
            return [], True
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
    return result, False


def main():
    if not REDIS_HOST:
        print("ERROR: REDIS_HOST is not set.", file=sys.stderr)
        sys.exit(1)

    vms, vm_err = get_vms()
    cts, ct_err = get_cts()

    payload: dict = {
        "host": socket.gethostname(),
        "last_seen": datetime.now(timezone.utc).isoformat(),
        "vms": vms,
        "cts": cts,
    }
    if vm_err or ct_err:
        payload["api_error"] = "unexpected_format"

    try:
        r = redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            password=REDIS_PASSWORD,
            socket_connect_timeout=5,
            socket_timeout=5,
            decode_responses=True,
        )
        r.set(REDIS_KEY, json.dumps(payload), ex=REDIS_TTL)
    except redis.RedisError as e:
        print(f"ERROR: failed to write to Redis: {e}", file=sys.stderr)
        sys.exit(1)

    status = f"[api_error] {payload['api_error']}" if "api_error" in payload else "[ok]"
    print(f"{status} {payload['last_seen']}  vms={len(vms)}  cts={len(cts)}")


if __name__ == "__main__":
    main()
