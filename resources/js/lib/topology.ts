// 拓樸圖共用設定與繪製（KnowledgeGraphWidget 與 MemoryGraph 共用）

import { select, zoom, zoomIdentity } from 'd3';

export interface Entity {
    id: number;
    name: string;
    type: string;
    observation_count: number;
}
export interface Relation {
    from: string;
    relation_type: string;
    to: string;
}
export interface GraphData {
    entities: Entity[];
    relations: Relation[];
}

/**
 * 各層內主機的左→右顯示順序。
 * 名稱需與知識圖譜 entity 的 name 完全一致；未列出者排在最後
 * （例如線上時才動態注入的 Proxmox 主機）。
 */
export const HOST_ORDER = [
    'Desktop',
    'Laptop',
    'GCP VM',
    'LightNode VM',
    '__unhosted__',
    'GitHub Pages',
    'Oracle VM1',
    'Oracle VM2',
];

/** 取得主機在 HOST_ORDER 的索引；未列出者回傳長度（排最後）。 */
export function hostOrderIndex(name: string): number {
    const i = HOST_ORDER.indexOf(name);

    return i === -1 ? HOST_ORDER.length : i;
}

const HOST_COLOR = '#a78bfa';
const PROJ_COLOR = 'var(--binary-primary)';
const ZT_COLOR = '#ffb441';

/** 各視圖的尺寸／樣式差異（小版 widget vs 全頁版）以 opts 吸收。 */
export interface TopologyOpts {
    /** clientWidth/Height 為 0 時的後備值 */
    fallbackW: number;
    fallbackH: number;
    pad: number;
    projH: number;
    projW: number;
    rowGap: number;
    /** 同層方框水平間距 */
    boxGap: number;
    /** <marker> id（兩視圖同頁不會並存，但仍各自命名避免衝突） */
    markerId: string;
    hostRx: number;
    projRx: number;
    /** ZeroTier hub 圓圈半徑 */
    hubR: number;
    hostFont: number;
    projFont: number;
    relFont: number;
}

/**
 * 繪製三層拓樸圖：① ZeroTier hub ② ZeroTier 成員或管理者 ③ 其他。
 * 邏輯單一來源；尺寸/樣式差異由 opts 提供。
 */
export function drawTopology(
    svgEl: SVGSVGElement,
    data: GraphData,
    opts: TopologyOpts,
) {
    const {
        pad,
        projH,
        projW,
        rowGap,
        boxGap,
        markerId,
        hostRx,
        projRx,
        hubR,
        hostFont,
        projFont,
        relFont,
    } = opts;
    const BOX_W = projW + pad * 2;

    const svg = select(svgEl);
    svg.selectAll('*').remove();
    const W = svgEl.clientWidth || opts.fallbackW;
    const H = svgEl.clientHeight || opts.fallbackH;

    const hosts = data.entities.filter((e) => e.type === 'host');
    const projects = data.entities.filter((e) => e.type === 'project');
    const hostNames = new Set(hosts.map((h) => h.name));
    const deployedOn = data.relations.filter(
        (r) => r.relation_type === 'deployed_on',
    );
    const hostHostRels = data.relations.filter(
        (r) => hostNames.has(r.from) && hostNames.has(r.to),
    );
    // ZeroTier hub = zerotier 關係匯聚的 to 端，畫成特別色圓圈而非主機方框
    const ztHubNames = new Set(
        hostHostRels
            .filter((r) => r.relation_type === 'zerotier')
            .map((r) => r.to),
    );
    // ZeroTier 成員 = 連到 hub 的主機（zerotier 關係的 from 端）
    const ztMembers = new Set(
        hostHostRels
            .filter(
                (r) =>
                    r.relation_type === 'zerotier' && !ztHubNames.has(r.from),
            )
            .map((r) => r.from),
    );
    // 管理/監控其他主機者也屬第二層（例如 GCP 管 Oracle），被管理者落第三層；
    // 深層管理鏈刻意壓平成「管理者層／被管理者層」，避免版面過高
    const managerHosts = new Set(
        hostHostRels
            .filter((r) => r.relation_type !== 'zerotier')
            .map((r) => r.from),
    );

    const hostProjects: Record<string, string[]> = {};
    hosts.forEach((h) => {
        hostProjects[h.name] = [];
    });
    const unhosted: string[] = [];
    projects.forEach((p) => {
        const rels = deployedOn.filter((r) => r.from === p.name);

        if (rels.length) {
            rels.forEach((r) => {
                if (hostProjects[r.to]) {
                    hostProjects[r.to].push(p.name);
                }
            });
        } else {
            unhosted.push(p.name);
        }
    });

    // 固定三層：① ZeroTier hub ② ZeroTier 成員或管理者 ③ 其他（被管理／被動部署／未部署）
    const layerFor = (name: string) =>
        ztHubNames.has(name)
            ? 0
            : ztMembers.has(name) || managerHosts.has(name)
              ? 1
              : 2;
    const layeredRows: string[][] = [[], [], []];
    hosts.forEach((h) => layeredRows[layerFor(h.name)].push(h.name));

    if (unhosted.length) {
        layeredRows[2].push('__unhosted__');
    }

    // 各層內左→右顯示順序
    layeredRows.forEach((row) =>
        row.sort((a, b) => hostOrderIndex(a) - hostOrderIndex(b)),
    );

    const rows = layeredRows.filter((r) => r.length);

    const boxH = (hostName: string) =>
        pad * 2 +
        Math.max(
            1,
            (hostName === '__unhosted__'
                ? unhosted
                : (hostProjects[hostName] ?? [])
            ).length,
        ) *
            (projH + pad);
    const rowH = (row: string[]) => Math.max(...row.map(boxH));

    const hostBox: Record<
        string,
        { x: number; y: number; w: number; h: number }
    > = {};
    const g = svg.append('g');
    const zoomBehavior = zoom<SVGSVGElement, unknown>()
        .scaleExtent([0.2, 2])
        .on('zoom', (e) => g.attr('transform', e.transform));
    svg.call(zoomBehavior).on('dblclick.zoom', null);

    svg.append('defs')
        .append('marker')
        .attr('id', markerId)
        .attr('viewBox', '0 -4 8 8')
        .attr('refX', 8)
        .attr('refY', 0)
        .attr('markerWidth', 5)
        .attr('markerHeight', 5)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-4L8,0L0,4')
        .attr('fill', HOST_COLOR);

    const totalH = rows.reduce((s, row) => s + rowH(row) + rowGap, 0) - rowGap;
    let startY = (H - totalH) / 2;

    rows.forEach((row) => {
        const rh = rowH(row),
            rowTotalW = row.length * BOX_W + (row.length - 1) * boxGap;
        let bx = (W - rowTotalW) / 2;
        row.forEach((hostName) => {
            const isUnhosted = hostName === '__unhosted__';
            const items = isUnhosted
                ? unhosted
                : (hostProjects[hostName] ?? []);
            const bh = boxH(hostName),
                by = startY + (rh - bh) / 2;
            hostBox[hostName] = { x: bx, y: by, w: BOX_W, h: bh };

            if (ztHubNames.has(hostName)) {
                // ZeroTier hub：特別色圓圈（中心對齊方框格，與虛線端點一致）
                const cx = bx + BOX_W / 2,
                    cy = by + bh / 2;
                g.append('circle')
                    .attr('cx', cx)
                    .attr('cy', cy)
                    .attr('r', hubR)
                    .attr('fill', ZT_COLOR + '22')
                    .attr('stroke', ZT_COLOR)
                    .attr('stroke-width', 1.5);
                g.append('text')
                    .text(hostName)
                    .attr('x', cx)
                    .attr('y', by - 5)
                    .attr('text-anchor', 'middle')
                    .attr('font-size', hostFont)
                    .attr('fill', ZT_COLOR);
                bx += BOX_W + boxGap;

                return;
            }

            g.append('rect')
                .attr('x', bx)
                .attr('y', by)
                .attr('width', BOX_W)
                .attr('height', bh)
                .attr('rx', hostRx)
                .attr('fill', isUnhosted ? 'transparent' : HOST_COLOR + '11')
                .attr(
                    'stroke',
                    isUnhosted ? 'var(--binary-outline-variant)' : HOST_COLOR,
                )
                .attr('stroke-width', 1.5)
                .attr('stroke-dasharray', isUnhosted ? '4,3' : 'none');
            g.append('text')
                .text(isUnhosted ? '未部署' : hostName)
                .attr('x', bx + BOX_W / 2)
                .attr('y', by - 5)
                .attr('text-anchor', 'middle')
                .attr('font-size', hostFont)
                .attr(
                    'fill',
                    isUnhosted ? 'var(--binary-outline)' : HOST_COLOR,
                );

            items.forEach((name, pi) => {
                const px = bx + pad,
                    py = by + pad + pi * (projH + pad);
                g.append('rect')
                    .attr('x', px)
                    .attr('y', py)
                    .attr('width', projW)
                    .attr('height', projH)
                    .attr('rx', projRx)
                    .attr('fill', PROJ_COLOR + '18')
                    .attr('stroke', PROJ_COLOR)
                    .attr('stroke-width', 1);
                g.append('text')
                    .text(name)
                    .attr('x', px + projW / 2)
                    .attr('y', py + projH / 2)
                    .attr('text-anchor', 'middle')
                    .attr('dominant-baseline', 'middle')
                    .attr('font-size', projFont)
                    .attr('fill', 'var(--binary-text)');
            });
            bx += BOX_W + boxGap;
        });
        startY += rh + rowGap;
    });

    hostHostRels.forEach((r) => {
        const s = hostBox[r.from],
            t = hostBox[r.to];

        if (!s || !t) {
            return;
        }

        const isZt = r.relation_type === 'zerotier';
        // ZeroTier：中心到中心、虛線、無箭頭（對等）；其餘：底→頂的階層箭頭
        const x1 = s.x + s.w / 2,
            y1 = isZt ? s.y + s.h / 2 : s.y + s.h;
        const x2 = t.x + t.w / 2,
            y2 = isZt ? t.y + t.h / 2 : t.y - 6;

        const line = g
            .append('line')
            .attr('x1', x1)
            .attr('y1', y1)
            .attr('x2', x2)
            .attr('y2', y2)
            .attr('stroke', isZt ? ZT_COLOR : HOST_COLOR)
            .attr('stroke-width', 1.2)
            .attr('opacity', 0.6);

        if (isZt) {
            line.attr('stroke-dasharray', '4,3');
        } else {
            line.attr('marker-end', `url(#${markerId})`);
        }

        g.append('text')
            .text(r.relation_type)
            .attr('x', (x1 + x2) / 2 + 4)
            .attr('y', (y1 + y2) / 2)
            .attr('font-size', relFont)
            .attr('fill', isZt ? ZT_COLOR : HOST_COLOR)
            .attr('opacity', 0.7);
    });

    svg.call(zoomBehavior.transform, zoomIdentity);
}
