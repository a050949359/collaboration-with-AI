<?php

# 預計做成 websocket 版本
# 背景觸發 event 讀檔存 cache, websocket 連線時直接讀 cache 回傳，減少即時讀檔的效能問題 
# 用 laravel/reverb 來實現
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SystemMonitorService
{
    // 快取秒數 (建議 5-10 秒，監控不需要毫秒級即時)
    protected $cacheTtl = 5;

    /**
     * 取得彙總報告 (具備快取機制)
     */
    public function getCachedReport()
    {
        return Cache::remember('system_monitor_stats', $this->cacheTtl, function () {
            return [
                'timestamp' => now()->toDateTimeString(),
                'cpu'       => $this->getCpuLoad(),
                'memory'    => $this->getMemoryStatus(),
                'storage'   => $this->getStorageStatus(),
                'network'   => $this->getNetworkInterfaces(),
            ];
        });
    }

    /**
     * 讀取 /proc/cpuinfo (CPU 型號與負載)
     */
    private function getCpuLoad()
    {
        $load = sys_getloadavg();
        $model = Cache::rememberForever('sys_cpu_model', function () {
            if (!is_readable("/proc/cpuinfo")) return "Unknown";
            $cpuinfo = file_get_contents("/proc/cpuinfo");
            preg_match('/model name\s+:\s+(.*)/', $cpuinfo, $matches);
            return $matches[1] ?? "Unknown";
        });

        return [
            'load_1min' => $load[0],
            'model'     => $model,
        ];
    }

    /**
     * 讀取 /proc/meminfo (記憶體狀態)
     */
    private function getMemoryStatus()
    {
        if (!is_readable("/proc/meminfo")) return null;

        $data = file_get_contents("/proc/meminfo");
        // 使用 preg_match_all 一次抓取多個數值，效率更高
        preg_match_all('/(MemTotal|MemAvailable):\s+(\d+)/', $data, $matches);
        $stats = array_combine($matches[1], $matches[2]);

        $totalKb = $stats['MemTotal'] ?? 0;
        $availKb = $stats['MemAvailable'] ?? 0;
        $usedKb  = $totalKb - $availKb;

        return [
            'total_gb'  => round($totalKb / 1048576, 2),
            'used_gb'   => round($usedKb / 1048576, 2),
            'usage_pct' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 2) : 0,
        ];
    }

    /**
     * 磁碟空間 (使用 PHP 內建函式)
     */
    private function getStorageStatus()
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free  = disk_free_space($path);
        
        return [
            'total_gb'  => round($total / 1073741824, 2),
            'free_gb'   => round($free / 1073741824, 2),
            'usage_pct' => round((($total - $free) / $total) * 100, 2),
        ];
    }

    /**
     * 網路介面列表
     */
    private function getNetworkInterfaces()
    {
        return Cache::remember('sys_network_interfaces', 3600, function () {
            if (!is_readable("/proc/net/dev")) return [];
            $data = file_get_contents("/proc/net/dev");
            $lines = explode("\n", $data);
            $interfaces = [];
            foreach ($lines as $line) {
                if (strpos($line, ':') === false || strpos($line, 'lo') !== false) continue;
                $parts = explode(':', $line);
                $interfaces[] = trim($parts[0]);
            }
            return $interfaces;
        });
    }
}