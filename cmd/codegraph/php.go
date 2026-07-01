package main

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

//go:embed extract.php
var phpExtractScript string

// buildGraph 是 ingest 層：跑各語言 extractor（Go in-process、PHP/TS exec 出去吐 JSON），
// 合併成一張圖。節點依 id 全域去重；邊直接累加。加語言=多接一個 extractor，這層不變。
func buildGraph(root string) ([]Node, []Edge, error) {
	absRoot, err := filepath.Abs(root)
	if err != nil {
		return nil, nil, err
	}

	var allNodes []Node
	var allEdges []Edge

	// Go
	gn, ge, err := extractGo(absRoot)
	if err != nil {
		return nil, nil, err
	}
	allNodes = append(allNodes, gn...)
	allEdges = append(allEdges, ge...)

	// PHP
	pn, pe, err := extractPHP(absRoot)
	if err != nil {
		fmt.Fprintf(os.Stderr, "warn: PHP extractor：%v\n", err)
	} else {
		allNodes = append(allNodes, pn...)
		allEdges = append(allEdges, pe...)
	}

	// TS/Vue
	tn, te, err := extractTS(absRoot)
	if err != nil {
		fmt.Fprintf(os.Stderr, "warn: TS extractor：%v\n", err)
	} else {
		allNodes = append(allNodes, tn...)
		allEdges = append(allEdges, te...)
	}

	if len(allNodes) == 0 {
		return nil, nil, fmt.Errorf("在 %s 底下沒抽到任何節點（無 Go module / 無 PHP / 解析失敗）", root)
	}

	// 全域去重（各語言 id 格式不同、不會互撞；此處防同語言重複）
	seen := map[string]bool{}
	nodes := make([]Node, 0, len(allNodes))
	for _, n := range allNodes {
		if n.ID == "" || seen[n.ID] {
			continue
		}
		seen[n.ID] = true
		nodes = append(nodes, n)
	}

	// 跨界 linker：把 TS 端的 HTTP_CALLS 佔位（HTTPURL <path>）對應到後端 route 節點
	edges := resolveHTTPCalls(nodes, allEdges)
	return nodes, edges, nil
}

// resolveHTTPCalls：前端 api.*() 產生的 HTTPURL 佔位邊，用正規化 URL 對應到 route 節點
// （route uri 的 {param} 與前端的 ${..}→* 都化為 *）。配不上的丟棄。
func resolveHTTPCalls(nodes []Node, edges []Edge) []Edge {
	re := regexp.MustCompile(`\{[^}]*\}`)
	routes := map[string][]string{}
	for _, n := range nodes {
		if n.Type == "route" {
			routes[re.ReplaceAllString(n.Name, "*")] = append(routes[re.ReplaceAllString(n.Name, "*")], n.ID)
		}
	}
	out := make([]Edge, 0, len(edges))
	matched, dropped := 0, 0
	for _, e := range edges {
		if e.Type == "HTTP_CALLS" && strings.HasPrefix(e.To, "HTTPURL ") {
			ids := routes[strings.TrimPrefix(e.To, "HTTPURL ")]
			if len(ids) == 0 {
				dropped++
				continue
			}
			for _, id := range ids {
				out = append(out, Edge{From: e.From, To: id, Type: "HTTP_CALLS", Confidence: e.Confidence, File: e.File, Line: e.Line})
				matched++
			}
			continue
		}
		out = append(out, e)
	}
	fmt.Fprintf(os.Stderr, "  · [link] HTTP_CALLS 配對 %d、未配對丟棄 %d\n", matched, dropped)
	return out
}

// extractPHP：找 root 下的 .php，exec 內嵌的 extract.php（需 php + nikic/php-parser），解析 JSON。
func extractPHP(absRoot string) ([]Node, []Edge, error) {
	files := findFilesBySuffix(absRoot, ".php")
	if len(files) == 0 {
		return nil, nil, nil
	}
	autoload, ok := findVendorAutoload(absRoot)
	if !ok {
		return nil, nil, fmt.Errorf("找不到 vendor/autoload.php（PHP extractor 需 nikic/php-parser）")
	}
	if _, err := exec.LookPath("php"); err != nil {
		return nil, nil, fmt.Errorf("找不到 php")
	}

	// 內嵌腳本寫到暫存檔再 exec
	tmp, err := os.CreateTemp("", "codegraph-extract-*.php")
	if err != nil {
		return nil, nil, err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.WriteString(phpExtractScript); err != nil {
		tmp.Close()
		return nil, nil, err
	}
	tmp.Close()

	cmd := exec.Command("php", tmp.Name(), absRoot, autoload)
	cmd.Stdin = strings.NewReader(strings.Join(files, "\n"))
	cmd.Stderr = os.Stderr
	out, err := cmd.Output()
	if err != nil {
		return nil, nil, fmt.Errorf("執行 php extractor 失敗：%w", err)
	}

	var res struct {
		Nodes []Node `json:"nodes"`
		Edges []Edge `json:"edges"`
	}
	if err := json.Unmarshal(out, &res); err != nil {
		return nil, nil, fmt.Errorf("解析 PHP extractor JSON 失敗：%w", err)
	}
	fmt.Fprintf(os.Stderr, "  · [php] %d 檔：%d 節點、%d 邊\n", len(files), len(res.Nodes), len(res.Edges))
	return res.Nodes, res.Edges, nil
}

// findVendorAutoload 從 absRoot 逐層往上找 vendor/autoload.php。
func findVendorAutoload(absRoot string) (string, bool) {
	dir := absRoot
	for {
		p := filepath.Join(dir, "vendor", "autoload.php")
		if fi, err := os.Stat(p); err == nil && !fi.IsDir() {
			return p, true
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return "", false
		}
		dir = parent
	}
}

// findFilesBySuffix 走訪 absRoot 收集指定副檔名的檔（跳過 vendor/.git/node_modules）。
func findFilesBySuffix(absRoot, suffix string) []string {
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
		if strings.HasSuffix(path, suffix) {
			out = append(out, path)
		}
		return nil
	})
	return out
}

// isSourceFile：目前變更偵測涵蓋的來源副檔名。
func isSourceFile(path string) bool {
	for _, s := range []string{".go", ".php", ".ts", ".vue", ".js"} {
		if strings.HasSuffix(path, s) {
			return true
		}
	}
	return false
}
