// 拓樸圖共用設定（KnowledgeGraphWidget 與 MemoryGraph 共用）

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
