#!/usr/bin/env python3
"""掃 public/geo/admin/*.json，產生前端下鑽用的索引 index.json。

前端需要這份索引來決定「這個國家能不能下鑽」——沒有索引就只能去 fetch 看會不會
404，那等於每點一國都先撞一次失敗請求，而且按鈕要先畫出來才知道該不該畫。

索引每一筆記的東西：

- `l1` / `l2`：第一層／第二層的 feature 數。`l1` 是 0 的國家不給下鑽入口。
- `parts` / `vertices`：第一層的塊數與頂點數。three-globe 是逐「塊」建幾何的，
  這兩個數字就是下鑽那一下的主要成本，留著方便抓誰是大戶（法國最大）。
- `bbox`：**本土那一群**的外接框，鏡頭用。
- `kb`：檔案大小，純粹方便排查。

## bbox 為什麼要分群算（踩過的坑）

不能用全部 feature 的外接框：法國含留尼旺與法屬圭亞那，框會橫跨半個地球，
鏡頭落到大西洋上。

也**不能取「面積最大的那一塊」**——這個檔裡裝的是行政區不是國家輪廓，所以最大塊
是「最大的行政區」：美國會對到阿拉斯加、俄羅斯對到薩哈、加拿大對到西北地方、
巴西對到亞馬遜州。法國只是碰巧（新阿基坦靠近國家中心）才看起來正常。

作法是把所有環用 **bbox 間距 ≤ 2°** 做單一連結分群（面積大的行政區 bbox 本來就
會互相貼著，用「中心點距離」則會讓阿拉斯加的東南狹長地帶串上華盛頓州），
取總面積最大的一群。經度一律先用 `(d + 180) % 360 - 180` 繞回錨點附近再比較，
換日線兩側才算得出真實距離（俄羅斯的楚科奇、斐濟、阿留申群島都靠這個）。

⚠️ 輸出的 bbox 是**展開過的經度**，可能超出 ±180（例如斐濟會是 177.2 ~ 180.6）。
前端算完中心點要自己繞回 -180..180。
"""

from __future__ import annotations

import json
import math
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ADMIN_DIR = REPO_ROOT / "public" / "geo" / "admin"
OUT_PATH = ADMIN_DIR / "index.json"


# 分群時判定「相鄰」的 bbox 間距上限（度）。2° 能把阿拉斯加跟本土 48 州分開，
# 又不會把俄羅斯那種大區塊拆散。
CLUSTER_GAP = 2.0


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


def mainland_bbox(rings: list[list]) -> list[float]:
    """把環分群，回傳總面積最大那一群的外接框。理由見檔頭。"""
    biggest = max(rings, key=ring_area)
    anchor = (
        min(p[0] for p in biggest) + max(p[0] for p in biggest)
    ) / 2

    def unwrap(lng: float) -> float:
        """把經度移到錨點附近的連續區間，換日線兩側才比得出遠近。"""
        return anchor + ((lng - anchor + 180) % 360 - 180)

    boxes = []

    for ring in rings:
        xs = [unwrap(p[0]) for p in ring]
        ys = [p[1] for p in ring]
        boxes.append((min(xs), min(ys), max(xs), max(ys), ring_area(ring)))

    parent = list(range(len(boxes)))

    def find(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]

        return i

    for i in range(len(boxes)):
        for j in range(i + 1, len(boxes)):
            a, b = boxes[i], boxes[j]
            gap_x = max(0.0, max(a[0], b[0]) - min(a[2], b[2]))
            gap_y = max(0.0, max(a[1], b[1]) - min(a[3], b[3]))
            mid_lat = math.radians((a[1] + a[3]) / 2)

            if math.hypot(gap_x * math.cos(mid_lat), gap_y) <= CLUSTER_GAP:
                ra, rb = find(i), find(j)

                if ra != rb:
                    parent[ra] = rb

    groups: dict[int, list[int]] = {}

    for i in range(len(boxes)):
        groups.setdefault(find(i), []).append(i)

    main = max(groups.values(), key=lambda ix: sum(boxes[i][4] for i in ix))

    return [
        round(min(boxes[i][0] for i in main), 3),
        round(min(boxes[i][1] for i in main), 3),
        round(max(boxes[i][2] for i in main), 3),
        round(max(boxes[i][3] for i in main), 3),
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

        index[path.stem] = {
            "l1": len(level1),
            "l2": len(level2),
            "parts": len(rings),
            "vertices": sum(len(r) for f in level1 for p in parts_of(f) for r in p),
            "bbox": mainland_bbox(rings),
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
