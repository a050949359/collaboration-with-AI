#!/usr/bin/env python3
"""掃 public/geo/admin/*.json，產生前端下鑽用的索引 index.json。

前端需要這份索引來決定「這個國家能不能下鑽」——沒有索引就只能去 fetch 看會不會
404，那等於每點一國都先撞一次失敗請求，而且按鈕要先畫出來才知道該不該畫。

索引每一筆記的東西：

- `l1` / `l2`：第一層／第二層的 feature 數。`l1` 是 0 的國家不給下鑽入口。
- `parts` / `vertices`：第一層的塊數與頂點數。three-globe 是逐「塊」建幾何的，
  這兩個數字就是下鑽那一下的主要成本，留著方便抓誰是大戶（法國最大）。
- `bbox`：**最大一塊**的外接框，不是全部 feature 的外接框。法國的 feature 含
  留尼旺、法屬圭亞那，全域 bbox 會橫跨半個地球，鏡頭算出來會落在大西洋上。
  取最大塊等於取本土。
- `kb`：檔案大小，純粹方便排查。
"""

from __future__ import annotations

import json
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ADMIN_DIR = REPO_ROOT / "public" / "geo" / "admin"
OUT_PATH = ADMIN_DIR / "index.json"


def parts_of(feature: dict) -> list:
    geometry = feature["geometry"]

    return (
        geometry["coordinates"]
        if geometry["type"] == "MultiPolygon"
        else [geometry["coordinates"]]
    )


def ring_area(ring: list) -> float:
    """鞋帶公式（度²）。只拿來比大小，不需要是真實球面面積。"""
    total = 0.0

    for (x1, y1), (x2, y2) in zip(ring, ring[1:]):
        total += x1 * y2 - x2 * y1

    return abs(total) / 2


def bbox_of(ring: list) -> list[float]:
    xs = [p[0] for p in ring]
    ys = [p[1] for p in ring]

    return [
        round(min(xs), 3),
        round(min(ys), 3),
        round(max(xs), 3),
        round(max(ys), 3),
    ]


def main() -> int:
    index: dict[str, dict] = {}

    for path in sorted(ADMIN_DIR.glob("Q*.json")):
        payload = json.loads(path.read_text(encoding="utf-8"))
        features = payload.get("features", [])

        level1 = [f for f in features if f["properties"].get("level") == 1]
        level2 = [f for f in features if f["properties"].get("level") == 2]

        if not level1:
            continue

        rings = [ring[0] for f in level1 for ring in parts_of(f)]
        biggest = max(rings, key=ring_area)

        index[path.stem] = {
            "l1": len(level1),
            "l2": len(level2),
            "parts": len(rings),
            "vertices": sum(len(r) for f in level1 for p in parts_of(f) for r in p),
            "bbox": bbox_of(biggest),
            "kb": round(path.stat().st_size / 1024),
        }

    OUT_PATH.write_text(
        json.dumps(index, ensure_ascii=False, separators=(",", ":")),
        encoding="utf-8",
    )

    heavy = sorted(index.items(), key=lambda kv: -kv[1]["parts"])[:5]

    print(f"  {len(index)} 國有第一層幾何 → {OUT_PATH.relative_to(REPO_ROOT)}")
    print(f"  索引大小：{OUT_PATH.stat().st_size / 1024:.0f} KB")
    print("  塊數最多的：")

    for qid, stats in heavy:
        print(f"    {qid}  第一層 {stats['l1']:>3} 個／{stats['parts']:>4} 塊／{stats['vertices']:>6,} 頂點")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
