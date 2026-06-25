# micro-heartbeat — Go 版 Proxmox 心跳 daemon

取代 `remote_scripts/micro-heartbeat/heartbeat.py`。常駐執行（systemd 管理），
每 `HEARTBEAT_INTERVAL` 秒以 `pvesh` 抓 VM/CT 狀態，寫 JSON 到專案主機 Redis（帶 TTL）。
key 不存在即離線，故 **interval 必須小於 TTL**。

payload 格式與 Python 版完全一致，Laravel 端 [`MicroHostStatus`](../../app/Services/MicroHost/MicroHostStatus.php) 不需更動。

優點：微型主機上**不需 python / pip install redis**，單一靜態 binary。

---

## 1. 編譯 binary

binary 已列入 `.gitignore`，需自行編譯。Proxmox 是 x86_64，可在專案主機交叉編譯後丟過去：

```bash
cd cmd/micro-heartbeat
GOOS=linux GOARCH=amd64 go build -o micro-heartbeat .
```

## 2. 複製到 Proxmox 主機

```bash
ssh root@<proxmox-zt-ip> 'mkdir -p /opt/micro-heartbeat'
scp micro-heartbeat micro-heartbeat.service micro-heartbeat.env.example \
    root@<proxmox-zt-ip>:/opt/micro-heartbeat/
```

## 3. 設定環境變數

在 Proxmox 主機上：

```bash
cd /opt/micro-heartbeat
cp micro-heartbeat.env.example micro-heartbeat.env
vi micro-heartbeat.env   # 把 REDIS_HOST 改成專案主機的 ZeroTier IP
```

| 變數 | 說明 | 預設 |
|------|------|------|
| `REDIS_HOST` | 專案主機 ZeroTier IP（必填） | — |
| `REDIS_PORT` | | `6379` |
| `REDIS_PASSWORD` | 無 auth 留空 | — |
| `REDIS_KEY` | 需與 `config/micro-host.php` 一致 | `micro:online` |
| `REDIS_TTL` | key TTL（秒） | `120` |
| `HEARTBEAT_INTERVAL` | 心跳間隔（秒），須 < TTL | `60` |
| `PVE_NODE` | Proxmox node 名稱 | `pve` |

## 4. 安裝 systemd service

```bash
ln -sf /opt/micro-heartbeat/micro-heartbeat.service /etc/systemd/system/micro-heartbeat.service
systemctl daemon-reload
systemctl enable --now micro-heartbeat
```

## 5. 開放專案主機 Redis port（同 Python 版）

在**專案主機**對 ZeroTier 網段開放 6379，並確認 Redis `bind` 含 ZeroTier IP：

```bash
ufw allow from 10.147.20.0/24 to any port 6379
# /etc/redis/redis.conf: bind 127.0.0.1 <project-host-zt-ip>
systemctl restart redis
```

## 驗證

```bash
# Proxmox 上看 log
journalctl -u micro-heartbeat -f
# 預期每 interval 一行：[ok] 2026-06-25T...Z  vms=N  cts=M

# 專案主機上確認 key
redis-cli get micro:online
```

`systemctl stop micro-heartbeat` 會主動刪除 key，dashboard 立即顯示離線（毋須等 TTL）。

---

> 沿用舊的 cron + Python 版？改用本 daemon 後請移除舊 crontab 行，避免兩者搶寫同一 key。
