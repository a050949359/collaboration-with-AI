/**
 * codegraph TS/Vue extractor
 *
 * 用 TypeScript Compiler API（自帶 TypeChecker，解析準）把 .ts/.js/.vue 解析成
 * 統一 JSON 契約 {nodes, edges}，供 codegraph Go 引擎合併。
 *   - .vue：用 @vue/compiler-sfc 抽 <script setup>/<script> 內容當虛擬 .ts（記行號 offset）
 *   - 呼叫 resolution：checker.getSymbolAtLocation → 宣告位置 → 只留指向「內部已索引檔」的邊
 *
 * 呼叫：node extract-ts.cjs <root>   檔案清單由 stdin 逐行給入。輸出：stdout JSON。
 * 需要 node_modules 的 typescript + @vue/compiler-sfc（Go 端以 NODE_PATH 指入）。
 */
const ts = require('typescript');
const sfc = require('@vue/compiler-sfc');
const fs = require('fs');

const root = process.argv[2] || process.cwd();
const input = fs.readFileSync(0, 'utf8').split('\n').map((s) => s.trim()).filter(Boolean);

// 虛擬檔表：tsFileName → { content|null(讀磁碟), orig, offset }
const virt = new Map();
const tsFiles = [];

for (const f of input) {
  if (f.endsWith('.vue')) {
    let src;
    try {
      src = fs.readFileSync(f, 'utf8');
    } catch {
      continue;
    }
    let desc;
    try {
      desc = sfc.parse(src).descriptor;
    } catch {
      continue;
    }
    const block = desc.scriptSetup || desc.script;
    if (!block) continue;
    const vname = f + '.ts';
    virt.set(vname, { content: block.content, orig: f, offset: block.loc.start.line - 1 });
    tsFiles.push(vname);
  } else {
    virt.set(f, { content: null, orig: f, offset: 0 });
    tsFiles.push(f);
  }
}

const options = {
  allowJs: true,
  noEmit: true,
  target: ts.ScriptTarget.ESNext,
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  skipLibCheck: true,
  allowNonTsExtensions: true,
  noResolve: false,
};

const host = ts.createCompilerHost(options);
const origGetSource = host.getSourceFile.bind(host);
host.getSourceFile = (fileName, langVersion, onError) => {
  const v = virt.get(fileName);
  if (v && v.content != null) {
    return ts.createSourceFile(fileName, v.content, langVersion, true);
  }
  return origGetSource(fileName, langVersion, onError);
};
const origReadFile = host.readFile.bind(host);
host.readFile = (fn) => {
  const v = virt.get(fn);
  if (v && v.content != null) return v.content;
  return origReadFile(fn);
};
const origFileExists = host.fileExists.bind(host);
host.fileExists = (fn) => virt.has(fn) || origFileExists(fn);

const program = ts.createProgram(tsFiles, options, host);
const checker = program.getTypeChecker();
const internal = new Set(tsFiles);

const nodes = new Map();
const edges = [];

function relOf(fileName) {
  const v = virt.get(fileName);
  const orig = v ? v.orig : fileName;
  return orig.startsWith(root + '/') ? orig.slice(root.length + 1) : orig;
}
function lineOf(fileName, node, sf) {
  const v = virt.get(fileName);
  const off = v ? v.offset : 0;
  return sf.getLineAndCharacterOfPosition(node.getStart()).line + 1 + off;
}
function addNode(id, type, name, fileName, node, sf) {
  if (!id || nodes.has(id)) return;
  nodes.set(id, { id, type, name, qualified: id, file: relOf(fileName), line: lineOf(fileName, node, sf) });
}

// 由宣告節點導出穩定 id（定義端與呼叫解析端共用同一規則 → 對得起來）
function declId(decl) {
  const sf = decl.getSourceFile();
  if (!internal.has(sf.fileName)) return null; // 外部（node_modules / lib.d.ts）
  const rel = relOf(sf.fileName);
  if (ts.isFunctionDeclaration(decl) && decl.name) return `${rel}:${decl.name.text}`;
  if (ts.isMethodDeclaration(decl) && decl.name) {
    const cls = decl.parent && decl.parent.name ? decl.parent.name.text : '?';
    return `${rel}:${cls}.${decl.name.getText()}`;
  }
  if (ts.isVariableDeclaration(decl) && decl.name && ts.isIdentifier(decl.name)) {
    // const foo = () => {} / function expr
    if (decl.initializer && (ts.isArrowFunction(decl.initializer) || ts.isFunctionExpression(decl.initializer))) {
      return `${rel}:${decl.name.text}`;
    }
  }
  if (ts.isClassDeclaration(decl) && decl.name) return `${rel}:${decl.name.text}`;
  return null;
}

function resolveCallee(expr) {
  let sym = checker.getSymbolAtLocation(expr);
  if (!sym) return null;
  if (sym.flags & ts.SymbolFlags.Alias) sym = checker.getAliasedSymbol(sym);
  const decls = sym.getDeclarations();
  if (!decls || !decls.length) return null;
  for (const d of decls) {
    const id = declId(d);
    if (id) return id;
  }
  return null;
}

// api.X.Y(...) → 後端 URL（依 routes.ts 單一來源）。回正規化路徑（${..} → *），否則 null。
function apiUrl(call) {
  const callee = call.expression;
  if (!ts.isPropertyAccessExpression(callee)) return null;
  // 找根識別字，須為從 lib/routes 匯入的 `api`
  let root = callee;
  while (ts.isPropertyAccessExpression(root.expression)) root = root.expression;
  const rootId = root.expression;
  if (!ts.isIdentifier(rootId) || rootId.text !== 'api') return null;
  let rsym = checker.getSymbolAtLocation(rootId);
  // api 是從 lib/routes 匯入的 → 解 alias 才能追到真正宣告檔
  if (rsym && rsym.flags & ts.SymbolFlags.Alias) rsym = checker.getAliasedSymbol(rsym);
  const rdecl = rsym && rsym.getDeclarations() && rsym.getDeclarations()[0];
  if (!rdecl || !rdecl.getSourceFile().fileName.includes('lib/routes')) return null;
  // 解析葉節點屬性 → 其箭頭函式 → 回傳的 URL 字面值
  const lsym = checker.getSymbolAtLocation(callee.name);
  const pd = lsym && lsym.getDeclarations() && lsym.getDeclarations()[0];
  if (!pd || !ts.isPropertyAssignment(pd)) return null;
  return urlFromArrow(pd.initializer);
}

function urlFromArrow(init) {
  if (!init || !ts.isArrowFunction(init)) return null;
  const b = init.body;
  if (ts.isStringLiteral(b) || ts.isNoSubstitutionTemplateLiteral(b)) return b.text;
  if (ts.isTemplateExpression(b)) {
    let s = b.head.text;
    for (const span of b.templateSpans) s += '*' + span.literal.text; // ${..} → *
    return s;
  }
  return null;
}

for (const fileName of tsFiles) {
  const sf = program.getSourceFile(fileName);
  if (!sf) continue;

  const funcStack = [];

  const visit = (node) => {
    let pushed = false;

    // ---- 定義 ----
    if (ts.isFunctionDeclaration(node) && node.name) {
      const id = declId(node);
      if (id) {
        addNode(id, 'func', node.name.text, fileName, node, sf);
        funcStack.push(id);
        pushed = true;
      }
    } else if (ts.isMethodDeclaration(node) && node.name) {
      const id = declId(node);
      if (id) {
        addNode(id, 'method', node.name.getText(), fileName, node, sf);
        funcStack.push(id);
        pushed = true;
      }
    } else if (
      ts.isVariableDeclaration(node) &&
      node.initializer &&
      (ts.isArrowFunction(node.initializer) || ts.isFunctionExpression(node.initializer)) &&
      ts.isIdentifier(node.name)
    ) {
      const id = declId(node);
      if (id) {
        addNode(id, 'func', node.name.text, fileName, node, sf);
        funcStack.push(id);
        pushed = true;
      }
    } else if (ts.isClassDeclaration(node) && node.name) {
      const id = declId(node);
      if (id) addNode(id, 'type', node.name.text, fileName, node, sf);
    }

    // ---- 呼叫 ----
    if (ts.isCallExpression(node) && funcStack.length) {
      const from = funcStack[funcStack.length - 1];
      const line = lineOf(fileName, node, sf);
      const url = apiUrl(node); // api.X.Y() → 後端 URL（HTTP_CALLS）
      if (url) {
        // 目標先記成 HTTPURL 佔位，Go linker 再對應到 route 節點
        edges.push({ from, to: 'HTTPURL ' + url, type: 'HTTP_CALLS', confidence: 0.9, file: relOf(fileName), line });
      } else {
        let target = node.expression;
        if (ts.isPropertyAccessExpression(target)) target = target.name;
        const to = resolveCallee(target);
        if (to) {
          edges.push({ from, to, type: 'CALLS', confidence: 1.0, file: relOf(fileName), line });
        }
      }
    }

    ts.forEachChild(node, visit);
    if (pushed) funcStack.pop();
  };

  visit(sf);
}

// 只留指向內部已定義節點的邊；HTTP_CALLS 的 HTTPURL 佔位保留給 Go linker 解析
const edgesOut = edges.filter(
  (e) => e.type === 'HTTP_CALLS' || nodes.has(e.to),
);

process.stdout.write(
  JSON.stringify({ nodes: Array.from(nodes.values()), edges: edgesOut })
);
