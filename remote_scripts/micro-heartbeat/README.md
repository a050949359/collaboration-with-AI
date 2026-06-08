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

## 3. 設定 crontab（每分鐘執行一次）

`heartbeat.py` 是 one-shot 腳本（跑完即退），由 crontab 每分鐘觸發一次。

先編輯 `micro-heartbeat.cron`，把 `REDIS_HOST` 改成專案主機的 ZeroTier IP：

```
REDIS_HOST=10.147.20.xxx
```

然後以 root 安裝（把內容貼進 crontab）：

```bash
crontab -e   # 以 root 執行，貼上 micro-heartbeat.cron 的內容
```

確認已安裝：

```bash
crontab -l
tail -f /var/log/micro-heartbeat.log   # 觀察每分鐘輸出
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
