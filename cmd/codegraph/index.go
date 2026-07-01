package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"go/ast"
	"go/token"
	"go/types"
	"os"
	"path/filepath"

	"golang.org/x/tools/go/packages"
)

// extractGo 是 Go extractor：探索 absRoot 底下所有 Go module，逐個抽取後合併。
// 節點依 id 去重；邊直接累加（各 module 是獨立 package main，不會有跨 module 亂連的邊）。
func extractGo(absRoot string) ([]Node, []Edge, error) {
	mods, err := findGoModules(absRoot)
	if err != nil {
		return nil, nil, err
	}
	if len(mods) == 0 {
		return nil, nil, nil // 沒有 Go module 不是錯誤（可能是純 PHP/TS 專案）
	}

	var nodes []Node
	var edges []Edge
	seen := map[string]bool{}

	for _, mod := range mods {
		mn, me, err := extractGoModule(mod, absRoot)
		if err != nil {
			fmt.Fprintf(os.Stderr, "warn: 抽取 %s 失敗：%v\n", mod, err)
			continue
		}
		for _, n := range mn {
			if n.ID == "" || seen[n.ID] {
				continue
			}
			seen[n.ID] = true
			nodes = append(nodes, n)
		}
		edges = append(edges, me...)
		fmt.Fprintf(os.Stderr, "  · [go] %s：%d 節點、%d 邊\n", relOrSelf(absRoot, mod), len(mn), len(me))
	}
	return nodes, edges, nil
}

// findGoModules：root 本身有 go.mod 就當單一 module；否則往下找所有含 go.mod 的目錄
// （找到即不再深入該 module，其 ./... 會涵蓋子套件）。跳過 vendor/.git/node_modules。
func findGoModules(absRoot string) ([]string, error) {
	if fi, err := os.Stat(filepath.Join(absRoot, "go.mod")); err == nil && !fi.IsDir() {
		return []string{absRoot}, nil
	}
	var mods []string
	err := filepath.Walk(absRoot, func(path string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if fi.IsDir() {
			if skipDirName(fi.Name()) {
				return filepath.SkipDir
			}
			return nil
		}
		if fi.Name() == "go.mod" {
			mods = append(mods, filepath.Dir(path))
			return filepath.SkipDir // 不深入此 module
		}
		return nil
	})
	return mods, err
}

// extractGoModule 是 Go extractor：載入單一 module（帶完整型別），抽節點與 CALLS 邊。
// relBase 用來把檔案路徑統一相對到掃描根（多 module 時避免各自 main.go 撞名）。
func extractGoModule(moduleDir, relBase string) ([]Node, []Edge, error) {
	fset := token.NewFileSet()
	cfg := &packages.Config{
		Mode: packages.NeedName | packages.NeedFiles | packages.NeedSyntax |
			packages.NeedTypes | packages.NeedTypesInfo | packages.NeedDeps | packages.NeedImports,
		Dir:  moduleDir,
		Fset: fset,
	}
	pkgs, err := packages.Load(cfg, "./...")
	if err != nil {
		return nil, nil, err
	}
	if packages.PrintErrors(pkgs) > 0 {
		fmt.Fprintf(os.Stderr, "warn: %s 載入時有型別/編譯錯誤，圖可能不完整\n", relOrSelf(relBase, moduleDir))
	}

	// 內部套件集合：只對「本 module 被索引到的套件」建節點/邊，濾掉 stdlib 與第三方。
	internal := map[string]bool{}
	for _, p := range pkgs {
		if p.PkgPath != "" {
			internal[p.PkgPath] = true
		}
	}

	var nodes []Node
	var edges []Edge
	seenNode := map[string]bool{}

	rel := func(pos token.Position) (string, int) {
		f := pos.Filename
		if r, err := filepath.Rel(relBase, f); err == nil {
			f = r
		}
		return f, pos.Line
	}
	addNode := func(n Node) {
		if n.ID == "" || seenNode[n.ID] {
			return
		}
		seenNode[n.ID] = true
		nodes = append(nodes, n)
	}

	for _, p := range pkgs {
		if p.TypesInfo == nil {
			continue
		}
		info := p.TypesInfo

		for _, file := range p.Syntax {
			for _, decl := range file.Decls {
				switch d := decl.(type) {

				case *ast.FuncDecl:
					obj, _ := info.Defs[d.Name].(*types.Func)
					if obj == nil {
						continue
					}
					fromID := obj.FullName()
					f, ln := rel(fset.Position(d.Name.Pos()))
					kind := "func"
					if sig, ok := obj.Type().(*types.Signature); ok && sig.Recv() != nil {
						kind = "method"
					}
					addNode(Node{ID: fromID, Type: kind, Name: obj.Name(), Qualified: fromID, File: f, Line: ln})
					if d.Body != nil {
						extractCalls(d.Body, fromID, info, internal, fset, relBase, &edges)
					}

				case *ast.GenDecl:
					for _, spec := range d.Specs {
						ts, ok := spec.(*ast.TypeSpec)
						if !ok {
							continue
						}
						obj, _ := info.Defs[ts.Name].(*types.TypeName)
						if obj == nil || obj.Pkg() == nil {
							continue
						}
						id := obj.Pkg().Path() + "." + obj.Name()
						f, ln := rel(fset.Position(ts.Name.Pos()))
						addNode(Node{ID: id, Type: "type", Name: obj.Name(), Qualified: id, File: f, Line: ln})
					}
				}
			}
		}
	}
	return nodes, edges, nil
}

// extractCalls：函式主體找呼叫，用 go/types 精準解析被呼叫者，只留指向本 module 內部的 CALLS 邊。
func extractCalls(body *ast.BlockStmt, fromID string, info *types.Info, internal map[string]bool, fset *token.FileSet, relBase string, edges *[]Edge) {
	ast.Inspect(body, func(n ast.Node) bool {
		call, ok := n.(*ast.CallExpr)
		if !ok {
			return true
		}
		var id *ast.Ident
		switch fun := call.Fun.(type) {
		case *ast.Ident:
			id = fun
		case *ast.SelectorExpr:
			id = fun.Sel
		default:
			return true
		}
		callee, _ := info.Uses[id].(*types.Func)
		if callee == nil || callee.Pkg() == nil {
			return true
		}
		if !internal[callee.Pkg().Path()] {
			return true
		}
		pos := fset.Position(call.Pos())
		f := pos.Filename
		if r, err := filepath.Rel(relBase, f); err == nil {
			f = r
		}
		*edges = append(*edges, Edge{From: fromID, To: callee.FullName(), Type: "CALLS", Confidence: 1.0, File: f, Line: pos.Line})
		return true
	})
}

func relOrSelf(base, p string) string {
	if r, err := filepath.Rel(base, p); err == nil && r != "." {
		return r
	}
	return filepath.Base(p)
}

// hashFiles 掃描 root 下所有 .go 目前的雜湊（相對 root），供 index 前的「有無變更」判斷與儲存。
func hashFiles(root string) (map[string]string, error) {
	absRoot, err := filepath.Abs(root)
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	err = filepath.Walk(absRoot, func(path string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if fi.IsDir() {
			if skipDirName(fi.Name()) {
				return filepath.SkipDir
			}
			return nil
		}
		if !isSourceFile(path) {
			return nil
		}
		b, err := os.ReadFile(path)
		if err != nil {
			return nil
		}
		sum := sha256.Sum256(b)
		if r, err := filepath.Rel(absRoot, path); err == nil {
			out[r] = hex.EncodeToString(sum[:])
		}
		return nil
	})
	return out, err
}

func sameHashes(a, b map[string]string) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if b[k] != v {
			return false
		}
	}
	return true
}
