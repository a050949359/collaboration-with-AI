package main

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

//go:embed extract-ts.cjs
var tsExtractScript string

// extractTS：找 root 下的 .ts/.js/.vue（跳過 .d.ts），exec 內嵌的 extract-ts.cjs
// （需 node + node_modules 的 typescript / @vue/compiler-sfc，以 NODE_PATH 指入），解析 JSON。
func extractTS(absRoot string) ([]Node, []Edge, error) {
	files := findFrontendFiles(absRoot)
	if len(files) == 0 {
		return nil, nil, nil
	}
	nodeModules, ok := findNodeModules(absRoot)
	if !ok {
		return nil, nil, fmt.Errorf("找不到 node_modules（TS extractor 需 typescript + @vue/compiler-sfc）")
	}
	if _, err := exec.LookPath("node"); err != nil {
		return nil, nil, fmt.Errorf("找不到 node")
	}

	tmp, err := os.CreateTemp("", "codegraph-extract-*.cjs")
	if err != nil {
		return nil, nil, err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.WriteString(tsExtractScript); err != nil {
		tmp.Close()
		return nil, nil, err
	}
	tmp.Close()

	cmd := exec.Command("node", tmp.Name(), absRoot)
	cmd.Stdin = strings.NewReader(strings.Join(files, "\n"))
	cmd.Stderr = os.Stderr
	cmd.Env = append(os.Environ(), "NODE_PATH="+nodeModules)
	out, err := cmd.Output()
	if err != nil {
		return nil, nil, fmt.Errorf("執行 node extractor 失敗：%w", err)
	}

	var res struct {
		Nodes []Node `json:"nodes"`
		Edges []Edge `json:"edges"`
	}
	if err := json.Unmarshal(out, &res); err != nil {
		return nil, nil, fmt.Errorf("解析 TS extractor JSON 失敗：%w", err)
	}
	fmt.Fprintf(os.Stderr, "  · [ts] %d 檔：%d 節點、%d 邊\n", len(files), len(res.Nodes), len(res.Edges))
	return res.Nodes, res.Edges, nil
}

// skipDirName：所有 extractor 共用的「非原始碼」目錄黑名單，避免掃到依賴、產物、快取。
// 特別是 public/build（Vite 編譯後的 minified bundle）會嚴重污染圖並灌爆邊數。
func skipDirName(name string) bool {
	switch name {
	case "node_modules", "vendor", ".git", "public", "storage", "dist", "build", "tmp", "bootstrap":
		return true
	}
	return false
}

func findNodeModules(absRoot string) (string, bool) {
	dir := absRoot
	for {
		p := filepath.Join(dir, "node_modules")
		if fi, err := os.Stat(p); err == nil && fi.IsDir() {
			return p, true
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return "", false
		}
		dir = parent
	}
}

// findFrontendFiles：.ts/.js/.vue（排除 .d.ts），跳過 vendor/.git/node_modules。
func findFrontendFiles(absRoot string) []string {
	var out []string
	filepath.Walk(absRoot, func(path string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if fi.IsDir() {
			if skipDirName(fi.Name()) {
				return filepath.SkipDir
			}
			return nil
		}
		if strings.HasSuffix(path, ".d.ts") {
			return nil
		}
		if strings.HasSuffix(path, ".ts") || strings.HasSuffix(path, ".js") || strings.HasSuffix(path, ".vue") {
			out = append(out, path)
		}
		return nil
	})
	return out
}
