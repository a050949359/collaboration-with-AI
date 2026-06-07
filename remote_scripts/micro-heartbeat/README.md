# Micro Heartbeat — 部署步驟

## 1. 安裝 Python redis 套件

```bash
pip3 install redis
```

## 2. 複製腳本到 Proxmox 主機

```bash
mkdir -p /opt/micro-heartbeat
cp heartbeat.py /opt/micro-heartbeat/
```

## 3. 設定 systemd service

編輯 `micro-heartbeat.service`，把 `REDIS_HOST` 改成專案主機的 ZeroTier IP：

```
Environment="REDIS_HOST=10.147.20.xxx"
```

然後安裝：

```bash
cp micro-heartbeat.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now micro-heartbeat
systemctl status micro-heartbeat
```

## 4. 開放專案主機 Redis port

在專案主機上，對 ZeroTier 網段開放 6379：

```bash
# 確認 ZeroTier 網段（通常是 10.x.x.x/24）
ip addr show ztxxxxxx

# UFW
ufw allow from 10.147.20.0/24 to any port 6379

# 或直接允許來源 IP
ufw allow from <proxmox-zt-ip> to any port 6379
```

## 5. Redis 監聽設定

確認 `/etc/redis/redis.conf` 有允許非本地連線：

```
# 加入 ZeroTier IP，或直接綁 0.0.0.0（不建議）
bind 127.0.0.1 10.147.20.yyy
```

重啟 Redis：`systemctl restart redis`

## 驗證

```bash
# 在 Proxmox 上手動跑一次
python3 /opt/micro-heartbeat/heartbeat.py

# 在專案主機上確認 key 存在
redis-cli get micro:online
```
