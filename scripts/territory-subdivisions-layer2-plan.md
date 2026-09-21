# Territory 第二層行政區（省/州 → 市/縣）規劃

> 第一層行政區已於 2026-09-04 全數完成（259/259，見 `territory-subdivisions-progress.md`）。
> 這份文件規劃**第二層**的範圍：不做全部 259 國，只挑熱門旅遊國家。

## 狀態：23 國第二層全數完成（2026-09-15，再加 Egypt/Tunisia/Brazil/Dominican Republic/South Africa）

Top 11 + Taiwan/Korea + Greece/Austria/Malaysia/Netherlands/Canada + Portugal/Saudi Arabia/UAE/
Vietnam/Morocco 共 18 國完成後，依同一套區域排名資料往下挑：Egypt（~15.8M）、Tunisia
（~10.3M）、Brazil（~9.3M）、Dominican Republic（~8.9M）、South Africa（~8.9M）。**這批中途
遇到一次 session 非預期中斷**（Brazil 27 州只跑了 9 州就被中斷，Dominican Republic/South
Africa 完全沒開始），下個 session 重新啟動後先查圖譜（不是相信舊 log，舊 session 的 scratchpad
已隨中斷消失）確認實際進度，再用 `--under Q155`（Brazil，冪等安全重跑）+ 兩個未跑國家接續，
這次把 log 放到跨 session 共用目錄（非 session 專屬 scratchpad）避免同樣問題再發生。最終：
Egypt（26/27 省 0 候選）、Tunisia（22/24 省 0 候選）為嚴重缺口；Dominican Republic 32/32 省
全數 0 候選，是繼 UAE 之後第二個「整國零第二層資料」案例；South Africa 9/9 省全數有資料，乾淨
完成；Brazil 27 州中 26 州有實際資料（Federal District 本身結構上無次層，非缺口），僅 Bahia
（417 候選）因 agy 輸出過長 3 次重試皆 JSON 解析失敗，屬腳本對超大候選清單的已知技術限制，
**不是資料缺口**。已知缺口與技術限制皆已在圖譜上標記（見下方「待補清單」）。

## 舊狀態記錄：Top 11 熱門旅遊國 + Taiwan/Korea 第二層全數完成（2026-09-11）

France/Spain/USA/China/Italy/Turkey/Mexico/Thailand/Germany/UK/Japan 11 國第二層行政區皆已跑完，
零系統性寫入失敗。接著加開第二批擴充範圍：Taiwan + South Korea（對本專案使用情境比觀光排名更
直接相關，見下方「擴充：Taiwan/Korea」），同樣零系統性寫入失敗，South Korea 更是 17 個一級行政區
全數精確吻合官方數字、零 P150 資料缺口。各國仍有已知的 Wikidata P150 資料缺口（見下方「待補
清單」），是資料源本身的問題，不是腳本或 agy 判斷錯誤，之後可視需要另外查證補建，不影響現有
資料的正確性。

## Resume prompt（若之後要擴大範圍到更多國家，開新對話貼這段）

```
接續 Territory MCP 第二層行政區的工作。先看記憶檔 project_territory_mcp_tool.md，
跟 scripts/territory-subdivisions-layer2-plan.md 這份規劃文件——18 國已全數完成：
Top 11 熱門旅遊國（France/Spain/USA/China/Italy/Turkey/Mexico/Thailand/Germany/UK/Japan）
+ Taiwan/Korea + Greece/Austria/Malaysia/Netherlands/Canada + Portugal/Saudi Arabia/UAE/
Vietnam/Morocco。若要擴大範圍，先用 WebSearch/WebFetch 查 Wikipedia「World Tourism
rankings」頁面（區域排名比單一全球表更容易查到，注意數字跨來源常不一致，只用來抓排序，
別當精確值）排除已做過的國家挑下一批 → 用法：
`python3 scripts/territory-import-subdivisions.py --under <國家 QID>`
（大國先用 --countries 分批，8-9 個一批）→ 背景執行 → Monitor 監看
`^===|agy accepted|failed|Error|Traceback` → 每批結束後 grep 檢查真正的 failed/Traceback
（不是 agy 的正常 rejected）並統計 accepted 總數 → 對 P150 全 0 候選的節點補
`add_observation(type=layer2_gap)` 標記 → 更新本文件的進度表 → 下一批/下一國。
```

## 範圍依據

2025 年國際遊客人數排行（來源：UN Tourism / 多方旅遊統計網站彙整，非本專案 Tour App 資料——該 App 資料是假資料，不能用）：

| 排名 | 國家 | 2025 遊客數（約） | QID | 第一層完成狀態 |
|---|---|---|---|---|
| 1 | France 🇫🇷 | 89M | Q142 | ✅ 25 個（arrondissement/région 混合，海外省另計） |
| 2 | Spain 🇪🇸 | 84M | Q29 | ✅ 19 個 |
| 3 | USA 🇺🇸 | 79M | Q30 | ✅ 56 個 |
| 4 | China 🇨🇳 | 66M | Q148 | ✅ 33 個 |
| 5 | Italy 🇮🇹 | 65M | Q38 | ✅ 20 個 |
| 6 | Turkey 🇹🇷 | 51M | Q43 | ✅ 81 個 |
| 7 | Mexico 🇲🇽 | 45M | Q96 | ✅ 32 個 |
| 8 | Thailand 🇹🇭 | 40M | Q869 | ✅ 77 個 |
| 9 | Germany 🇩🇪 | 40M | Q183 | ✅ 16 個 |
| 10 | UK 🇬🇧 | 39M | Q145 | ✅ 4 個 |
| 11 | Japan 🇯🇵 | 32M | Q17 | ✅ 47 個 |

## 擴充：Taiwan/Korea（2026-09-11，使用者確認優先於觀光排名 12-20 名）

Top 11 完成後使用者要求「再找下一批」，因兩國排名未進觀光前 15 名但對本專案（Taiwan 航空/旅遊
App）使用情境更直接相關，優先於單純接續觀光排名擴充：

| 國家 | QID | 第一層完成狀態 |
|---|---|---|
| Taiwan 🇹🇼 | Q865 | ✅ 6 個（6 都/縣市） |
| South Korea 🇰🇷 | Q884 | ✅ 17 個（1 特別市+6 廣域市+1 特別自治市+8 道+1 特別自治道） |

## 擴充二：Greece/Austria/Malaysia/Netherlands/Canada（2026-09-11，接續觀光排名）

Taiwan/Korea 之後接續按 Wikipedia「World Tourism rankings」2024 年表格（比先前搜尋到的多方
矛盾數字更可靠的單一來源）重新核對排序，往下找到的下一批：

| 國家 | QID | 第一層完成狀態 |
|---|---|---|
| Greece 🇬🇷 | Q41 | ✅ 14 個（13 大區+聖山自治修道院區） |
| Austria 🇦🇹 | Q40 | ✅ 9 個 |
| Malaysia 🇲🇾 | Q833 | ✅ 16 個 |
| Netherlands 🇳🇱（本土，Q55） | Q55 | ⚠️ 本次才補建（見下方說明） |
| Canada 🇨🇦 | Q16 | ✅ 13 個 |

**Netherlands 特殊狀況**：Q55（荷蘭本土）本身此前從未跑過第一層行政區——2026-08-21 那批只在
Kingdom of Netherlands（Q29999）底下把 Q55 當成一個節點掛上去（與 Aruba/Curaçao/Sint Maarten
同級），Q55 自己的 12 個省份從未建立。本次用 `--under Q29999 --countries Q55` 讓腳本對 Q55
本身跑 P150 查詢，補建 12 省 + Bonaire/Sint Eustatius/Saba 3 個特別自治市（共 15 個一級單位），
才能接著跑真正的第二層（市鎮）。**這其實是本輪掃到的一個第一層缺口，不是單純的第二層工作**，
之後若還有類似「Kingdom 包一層、本體從未單獨建過第一層」的國家要留意這個模式。

## 擴充三：Portugal/Saudi Arabia/UAE/Vietnam/Morocco（2026-09-14，接續觀光排名）

Greece/Austria/Malaysia/Netherlands/Canada 之後，用 Wikipedia「World Tourism rankings」頁面
的區域排名表（Europe/Asia/Americas/Africa，2024-2025 各區前十）重新核對，排除已做過的國家，
往下找到的下一批：

| 國家 | QID | 第一層完成狀態 |
|---|---|---|
| Portugal 🇵🇹 | Q45 | ✅ 20 個（本土 18 區+Madeira+Azores 兩自治區） |
| Saudi Arabia 🇸🇦 | Q851 | ✅ 13 個 |
| UAE 🇦🇪 | Q878 | ✅ 7 個（7 酋長國） |
| Vietnam 🇻🇳 | Q881 | ✅ 34 個（2025 行政區重劃後新制，原 63 省市合併而來） |
| Morocco 🇲🇦 | Q1028 | ✅ 10 個（不含西撒哈拉主張的 2 個爭議大區，比照既有西撒哈拉排除慣例） |

5 國一次跑完，零系統性寫入失敗（全程無 Traceback/Error）。Saudi Arabia/UAE/Portugal 三國有
嚴重 P150 資料缺口（見下方待補清單），Vietnam/Morocco 資料完整度良好。

## 擴充四：Egypt/Tunisia/Brazil/Dominican Republic/South Africa（2026-09-15，接續觀光排名）

擴充三之後，同樣依區域排名表往下挑的一批：

| 國家 | QID | 第一層完成狀態 |
|---|---|---|
| Egypt 🇪🇬 | Q79 | ✅ 27 個省 |
| Tunisia 🇹🇳 | Q948 | ✅ 24 個省 |
| Brazil 🇧🇷 | Q155 | ✅ 27 個（26 州+聯邦區） |
| Dominican Republic 🇩🇴 | Q786 | ✅ 32 個省 |
| South Africa 🇿🇦 | Q258 | ✅ 9 個省 |

**過程中遇到一次 session 中斷**：上一輪執行到 Brazil 中途（27 州跑了 9 州）時 session 意外
結束，且該 session 專屬的 scratchpad 目錄隨之消失，原始 log 不可考。下個 session 重新開始時
沒有直接相信「應該跑完了」，而是先用 `read_graph` 逐一查 Egypt/Tunisia/Brazil/Dominican
Republic/South Africa 這 5 國每個一級行政區底下有沒有第二層 relation，確認 Egypt/Tunisia 其實
已經正常跑完（只是資料本身缺口嚴重、看起來像沒跑完），Brazil 卡在中途，Dominican
Republic/South Africa 完全沒開始。之後用 `--under Q155`（不加 `--countries`，冪等安全重跑
全部 27 州）接續 Brazil，再跑 Dominican Republic/South Africa，並把這次的 log 改放到跨
session 共用的目錄（不是 session 專屬 scratchpad），避免下次再發生同樣的「log 隨中斷消失」
問題。

Brazil 重跑時另外發現：Rio Grande do Sul（495 候選）、Bahia（417 候選）、Goiás（246 候選）
這三個候選數特別多的州，agy 回傳的 JSON 被截斷導致解析失敗，腳本正常跳過（非崩潰）。單獨重跑
Rio Grande do Sul、Goiás 後都成功；Bahia 連續 3 次重跑都在不同位置解析失敗（截斷點每次不同，
非固定 bug 而像是輸出長度機率性超限），判斷為腳本尚未處理「超大候選清單」的已知技術限制，
停止重試並記錄，之後如需補齊可考慮讓腳本對候選數超過某個門檻的上層節點自動分批查詢。

最終結果：Egypt 僅 1/27 省有資料（總計 7 筆），Tunisia 僅 2/24 省有資料（總計 21 筆），兩國
皆為嚴重 P150 缺口；Dominican Republic 32/32 省全數 0 候選（整國缺口，同 UAE 案例）；
South Africa 9/9 省全數有資料，零缺口；Brazil 27 州中 26 州有資料（Federal District 結構上
本來就無次層），僅 Bahia 因上述技術限制暫缺。

## 待辦（下次接續）

0. **下次接續請先讀這一條**：France/Spain/USA/China/Italy/Turkey 已完成（見下表）；Mexico 執行到一半被中斷，直接重跑 `--under Q96`（不加 --countries）即可安全恢復；剩下 Thailand/Germany/UK/Japan 尚未開始。繼續時比照本次已建立的模式：`--under <QID>`（大國先 `--countries` 分批，8-9 個一批）→ 背景執行 → Monitor 監看 `^===|agy accepted|failed|Error|Traceback` → 每批結束後 grep 檢查真正的 failed/Traceback（不是 agy 的正常 rejected）並統計 accepted 總數 → 更新本文件的進度表 → 下一批/下一國。
1. ✅ **技術前提已完成**（2026-09-09，commit `ac02f46`/`6024d9b`/`2784ebf`）：`territory-import-subdivisions.py` 新增 `--under <QID>` 支援任意父層 QID（不再寫死國家層），並修掉兩個真實 bug（judge prompt 誤把「description 沒寫上層名稱」當隸屬存疑；單一上層節點的 SPARQL 逾時未接住會讓整支腳本掛掉）。
2. 範圍確認：目前先鎖定上表 11 國，之後可視需要加入 Taiwan/Korea 等對本專案使用情境更重要但排名未進前 15 的國家。
3. **大國（USA/China/Turkey/Thailand）範圍決定（2026-09-09，使用者確認）：全部展開，但每個國家內部分批跑**（例如美國 56 州分 7 批、每批約 8 個，每批跑完檢查一次錯誤/異常再繼續），不整批一次 56 州齊發——方便中途檢查、也避免單次 agy 呼叫規模失控。

## 進度追蹤（第二層，11 國）

| 國家 | QID | 狀態 | 第二層筆數 | 備註 |
|---|---|---|---|---|
| France 🇫🇷 | Q142 | ✅ 完成（2026-09-09） | 299 | 25 個第一層全跑，零寫入失敗 |
| Spain 🇪🇸 | Q29 | ✅ 完成（2026-09-09） | 45 | 19 個第一層全跑，零寫入失敗；Asturias/Murcia 的「省」被 Wikidata 標記 historical 正確濾除 |
| USA 🇺🇸 | Q30 | ✅ 完成（2026-09-09） | ~3239 | 56 州/屬地分 7 批跑完，零系統性寫入失敗；1 筆手動補建（見下方待補清單）、1 筆 refresh_observations 暫時性連線失敗待補 |
| China 🇨🇳 | Q148 | ✅ 完成（2026-09-09） | 466 | 33 個第一層分 5 批跑完，零系統性寫入失敗；重慶 2 個/山東 1 個/海南 1 個拒絕皆為真已廢除或非行政區實體，判斷正確 |
| Italy 🇮🇹 | Q38 | ✅ 完成（有已知缺口，2026-09-09） | 166 | 20 個大區全跑，零系統性寫入失敗；Sicily/Friuli-Venezia Giulia 因當地已廢省改制，候選清單只剩已廢除的舊省 QID，正確全拒但造成真實資料缺口（見下方待補清單） |
| Turkey 🇹🇷 | Q43 | ✅ 完成（2026-09-09） | 983 | 81 省分 9 批跑完，零系統性寫入失敗；每省普遍有「鎮/市中心」與正式「縣」重複實體的雜訊，agy 一致正確過濾，accepted 數字與真實縣數逐一核對吻合 |
| Mexico 🇲🇽 | Q96 | ✅ 完成（2026-09-10） | 464 | 32 個聯邦實體（31 州+墨西哥市）全跑完，零系統性寫入失敗；11 州有嚴重 P150 資料缺口（見下方待補清單），屬 Wikidata 資料源本身問題非腳本/agy 誤判 |
| Thailand 🇹🇭 | Q869 | ✅ 完成（2026-09-10） | 926 | 77 府分 9 批跑完，零系統性寫入失敗；多數府候選數與官方縣數精確吻合（如 Chiang Mai 25/25、Ubon Ratchathani 25/25、Nakhon Ratchasima 32/32） |
| Germany 🇩🇪 | Q183 | ✅ 完成（2026-09-10） | 218 | 16 邦一次跑完，零系統性寫入失敗；Berlin 12/12、Bavaria 7/7、Bremen 2/2 皆精確吻合官方數；`Q1194`（Schleswig-Holstein）本身缺 label observation（見下方待補） |
| UK 🇬🇧 | Q145 | ✅ 完成（有已知缺口，2026-09-10） | 74 | 4 個構成國全跑完，零系統性寫入失敗；Scotland 32/32、N. Ireland 11/11、Wales 22/22 精確吻合；England（`Q21`）只有 9 個候選，實際應有 300+ 地方政府單位，嚴重 P150 缺口（見下方待補） |
| Japan 🇯🇵 | Q17 | ✅ 完成（有已知缺口，2026-09-10/11） | 902 | 47 都道府縣全跑完（6 批），零系統性寫入失敗；過程中發現並修正 judge prompt 系統性誤判「郡」（gun/district，無治理功能地理分組）為正式行政區的 bug（見下方說明），修正前已誤建的 21 個郡 entity 已刪除；Hokkaidō 特殊結構（14 個振興局 subprefecture）正確辨識；Tokyo 62/62 精確吻合官方數；多個縣有 P150 缺口（見下方待補清單） |
| Taiwan 🇹🇼 | Q865 | ✅ 完成（2026-09-11） | 157 | 6 都/縣市一次跑完，零真正失敗（Kaohsiung 首次遇到 SPARQL 逾時，單獨重跑後正常，屬既有已知的暫時性網路問題）；Taoyuan 13/13、Tainan 37/37、New Taipei 28/29、Taichung 29/29、Taipei 12/12、Kaohsiung 38/38，皆精確吻合官方行政區數 |
| South Korea 🇰🇷 | Q884 | ✅ 完成（2026-09-11） | 252 | 17 個一級行政區一次跑完，零系統性寫入失敗，**零 P150 資料缺口**——每一個都精確吻合官方市郡區數（Seoul 25/25、Busan 16/16、Daegu 9/9、Incheon 10/10、Gwangju 5/5、Daejeon 5/5、Ulsan 5/5、Sejong 24/24、Gyeonggi 31/31、8 道 11~22 不等皆精確吻合、Jeju 2/2），是本輪資料完整度最乾淨的國家 |
| Greece 🇬🇷 | Q41 | ✅ 完成（2026-09-11） | 74 | 14 個大區全跑完，零系統性寫入失敗；Mount Athos 自治修道院區 0 候選正確（無一般行政區劃），其餘 13 個大區皆有實際資料 |
| Austria 🇦🇹 | Q40 | ✅ 完成（2026-09-11） | 116 | 9 個邦一次跑完，零系統性寫入失敗，Vienna 23/23、Styria 13/13 等多數精確吻合官方郡/區數 |
| Malaysia 🇲🇾 | Q833 | ✅ 完成（有嚴重缺口，2026-09-11） | 29 | 16 個州一次跑完，零系統性寫入失敗；但只有 Sarawak（12/12）、Sabah（5/5）、Perak（12/12）有實際資料，其餘 9 州（Negeri Sembilan/Johor/Malacca/Kelantan/Penang/Kedah/Terengganu/Selangor/Pahang）P150 全數 0 候選，是本輪目前最嚴重的資料缺口（見下方待補清單） |
| Netherlands 🇳🇱（本土 Q55） | Q55 | ✅ 完成（本次補建第一層，2026-09-11） | 339（+15 補建的一級單位） | 12 省先行補建（見上方擴充二說明），再跑第二層市鎮：South Holland 50/111、North Holland 43/74、Flevoland 6/6、Zeeland 13/18、Groningen 9/30、Friesland 18/35、Gelderland 51/58、Drenthe 12/12、Overijssel 25/51、Utrecht 26/39、Limburg 30/62、North Brabant 56/73，多數拒絕皆為 2010s 大規模市鎮合併後的舊制正確過濾，非缺口；Bonaire/Saba/Sint Eustatius 三特別自治市 0 候選正確（單一市鎮無次級） |
| Canada 🇨🇦 | Q16 | ✅ 完成（有嚴重缺口，2026-09-11） | 74 | 13 個省/地區一次跑完，零系統性寫入失敗；但 Ontario/Alberta/Manitoba/Saskatchewan/Newfoundland and Labrador/Yukon/Nunavut 共 7 個 P150 全數 0 候選，只有 Nova Scotia（5）、New Brunswick（15）、British Columbia（30）、PEI（3）、Northwest Territories（5）、Quebec（16）有實際資料，同樣是本輪嚴重缺口區（見下方待補清單） |
| Portugal 🇵🇹 | Q45 | ✅ 完成（有嚴重缺口，2026-09-14） | 74 | 20 個區一次跑完，零系統性寫入失敗；但 13/20 區 P150 全數 0 候選，只有 Lisbon（16）、Setúbal（13）、Porto（18）、Braga（14）、Madeira（11）、Bragança（1）、`Q274118`（1，本身還缺 label observation）有實際資料，Azores 自治區也在 0 候選之列（見下方待補清單） |
| Saudi Arabia 🇸🇦 | Q851 | ✅ 完成（有嚴重缺口，2026-09-14） | 3 | 13 省一次跑完，零系統性寫入失敗；但 11/13 省 P150 全數 0 候選，只有 Northern Borders Province（1）、Riyadh Province（2）有實際資料，是本輪目前候選覆蓋率最差的國家之一（見下方待補清單） |
| UAE 🇦🇪 | Q878 | ✅ 完成（全數缺口，2026-09-14） | 0 | 7 個酋長國一次跑完，零系統性寫入失敗；但全部 7/7 酋長國 P150 皆 0 候選，本輪唯一「整個國家零第二層資料」的案例（見下方待補清單） |
| Vietnam 🇻🇳 | Q881 | ✅ 完成（2026-09-14） | 360 | 34 個省市（2025 重劃後新制）一次跑完，零系統性寫入失敗；拒絕的候選多為改制前已廢除的舊縣（description 標示 former district），agy 判斷正確 |
| Morocco 🇲🇦 | Q1028 | ✅ 完成（2026-09-14） | 69 | 10 個大區（不含西撒哈拉主張的 2 個爭議大區）一次跑完，零系統性寫入失敗，10/10 皆有實際資料，本輪資料完整度良好的國家之一 |
| Egypt 🇪🇬 | Q79 | ✅ 完成（有嚴重缺口，2026-09-15） | 7 | 27 省一次跑完，零系統性寫入失敗；但 26/27 省 P150 全數 0 候選，只有 Aswan Governorate（7）有實際資料，本輪目前候選覆蓋率最差的國家（見下方待補清單） |
| Tunisia 🇹🇳 | Q948 | ✅ 完成（有嚴重缺口，2026-09-15） | 21 | 24 省一次跑完，零系統性寫入失敗；但 22/24 省 P150 全數 0 候選，只有 Ben Arous Governorate（7）、Sfax Governorate（14）有實際資料（見下方待補清單） |
| Brazil 🇧🇷 | Q155 | ✅ 完成（有已知缺口，2026-09-15） | 3592 | 27 州（26 州+聯邦區）中途遇 session 中斷，接續補跑；26/27 州有實際資料（Federal District `Q119158` 結構上本來就無次層，非缺口），僅 Bahia（`Q40430`，417 候選）因 agy 輸出過長 3 次重試皆 JSON 解析失敗，屬腳本尚未處理的技術限制（見下方待補清單） |
| Dominican Republic 🇩🇴 | Q786 | ✅ 完成（全數缺口，2026-09-15） | 0 | 32 省一次跑完，零系統性寫入失敗；但全部 32/32 省 P150 皆 0 候選，繼 UAE 之後本輪第二個「整個國家零第二層資料」案例（見下方待補清單） |
| South Africa 🇿🇦 | Q258 | ✅ 完成（2026-09-15） | 53 | 9 省一次跑完，零系統性寫入失敗，9/9 皆有實際資料，資料完整度良好 |

## 待補清單（不影響已完成筆數，事後一次性補，不要單筆插隊補）

> **2026-09-11／2026-09-14 已在圖譜上標記**：下方清單中所有「第二層資料缺口」的節點（不含純缺
> label observation、USA 個別子節點問題、Sardinia 單筆分類問題這幾類）都已在對應的父節點上加
> 一筆 `type=layer2_gap` 的 observation，內容摘要同下方文字。⚠️ `search_nodes` 只比對節點名稱/
> 節點 type/observation **content**，不比對 observation 自己的 type 欄位，所以搜尋字串
> `"layer2_gap"` 找不到——要嘛用 `read_graph(entity_name=QID)` 逐一查（QID 見下方清單），
> 要嘛用 `search_nodes(query="資料缺口")` 撈（2026-09-11 那批可比對到 35/37 筆，Sicily/
> Friuli-Venezia Giulia 這兩筆用詞不同未含該字串，仍需查 QID `Q1460`/`Q1250`；2026-09-14
> 起新增的批次用詞統一含「資料缺口」，皆可被 `search_nodes` 撈到）。共 149 筆：Spain 1、
> USA 1、Italy 2、Mexico 11、UK 1、Japan 5、Malaysia 9、Canada 7、Saudi Arabia 11、UAE 7、
> Portugal 13、Egypt 26、Tunisia 22、Dominican Republic 32、Brazil 1（Bahia，技術限制非
> P150 缺口，內容文字不同）。

- **France**：`Q15104`（法國某個第一層大區）缺 label observation，屬於 2026-08 第一層匯入時就存在的舊資料缺口（refresh_observations 的既知 ~5-10% 暫時性失敗），非本次新增。
- **France**：Balearic Islands 同類疑似 candidates:0 的資料缺口（西班牙段，非法國——見下）待查證是否為 Wikidata P150 真缺，或本來就無次一層。
- **Spain**：`Q107356467`（Balearic Islands）P150 回傳 0 候選，西班牙其他小型單一省份自治區（Cantabria/La Rioja/Ceuta/Melilla/Navarre）0 候選屬預期（單一省份/單一市，省級已名存實亡或本來就無次一層），Balearic Islands 略可疑，待查證。
- **USA**：`Q500751`（Kentucky 某郡，第 6 批）refresh_observations 觸發時遇到連線重置（Connection reset by peer），entity/relation 已正確寫入，只差 observation，需補一次 `refresh_observations(Q500751)`。
- **USA**：`Q47894`（Broome County, New York）Wikidata 無英文 label 導致 agy 誤拒，已依既有慣例（採同儕多數 type）手動補建，不需再處理。
- **USA**：`Q11703`（US Virgin Islands）P150 回傳 0 候選，疑似 Wikidata 資料缺口（其他美國屬地如 Guam/American Samoa/Northern Mariana Islands 都有正常候選），待查證。
- **China**：33 個第一層全數完成，重慶/山東/海南各 1-2 筆拒絕皆為真已廢除或非行政區實體，無資料缺口。
- **Italy**：`Q1460`（Sicily）9 個候選全部是已廢除的舊省 QID（義大利 2015 年改制，西西里已廢省改為「自由市鎮聯合體」+ Palermo 大都市），P150 沒有連到任何新制單位的 QID，agy 正確全拒但等於這個大區完全沒有第二層資料——需要之後另外搜尋「自由市鎮聯合體」（libero consorzio comunale）＋ Palermo 大都市的 Wikidata QID 手動補建，不能靠 P150 抓到。
- **Italy**：`Q1250`（Friuli-Venezia Giulia）4 個候選同樣全部是已廢除的舊省 QID（該大區 2017 年廢省，改為直轄市鎮），跟 Sicily 同類缺口，需另外查證現行單位（可能是自治大區直轄市鎮，沒有中間層，比照 Aosta Valley 模式直接抓 comune）。
- **Italy**：`Q1462`（Sardinia）`Q23498165`「Province of South Sardinia」被 Wikidata 標記為 `former province of Italy` 而遭 agy 正確依規則拒絕，但南薩丁尼亞（Sud Sardegna）在義大利現行體制裡應該是**現行**省級單位（2016 年由多個小省合併而成）——這筆很可能是 Wikidata 本身的分類標記錯誤/過時，需要查證後可能要手動覆寫 type 補建，不是 agy 誤判。
- **Mexico**：11 個州的 P150 只回傳極少候選（實際市鎮數遠高於候選數），確認是 Wikidata P150 連結缺失，非 agy 誤判：`Q80007`（Tamaulipas，1/43）、`Q80269`（Zacatecas，2/58）、`Q80903`（Hidalgo，0/84）、`Q82112`（State of Mexico，0/125，本身還缺 label observation）、`Q82681`（Tlaxcala，0/60）、`Q34110`（Oaxaca，1/570）、`Q46475`（Guanajuato，1/46）、`Q53079`（Coahuila，1/38）、`Q60130`（Veracruz，11/212）、`Q60158`（Guerrero，0/81）、`Q60176`（Yucatán，33/106）。
- **Germany**：`Q1194`（Schleswig-Holstein）本身在 territory 圖譜缺 label observation（顯示成裸 QID，同 France `Q15104`/Mexico `Q82112` 那類第一層舊缺口），需 refresh_observations 補 label。
- **UK**：`Q21`（England）P150 只回傳 9 筆候選（實際應有 300+ 個地方政府單位，含都會自治市/單一管理區/郡議會等混合層級），屬 Wikidata P150 嚴重缺口，且英格蘭地方政府結構本身也較複雜非單純市鎮制，需之後另外規劃查詢方式；本身也缺 label observation（顯示成裸 QID）。
- **Japan**：**系統性 bug 已修正（2026-09-10，commit `19868bb`）**——judge prompt 原本會把「郡」（gun/district of Japan，明治郡制廢除後僅剩地址用地理分組、無議會無治理機關）誤判為正式行政區接受，因為它結構上仍通過 P150。已修正前誤建的 21 個郡 entity（Aomori 8 個、Fukushima 13 個）已手動刪除，新增判斷規則明確排除此類「有 P150 但本身無治理功能的地理/歷史分組」（同一類的已知案例還有 Ireland 傳統四省）。修正後全程重新驗證，郡類雜訊持續被正確拒絕。
- **Japan**：多個縣有 P150 資料缺口（候選數遠低於實際市町村數，非 agy 誤判）：`Q48326`（Iwate，14/33）、`Q132751`（Shimane，8/19）、`Q133935`（Tottori，4/19，日本面積最小縣）、`Q127877`（Nagano，20/77）、`Q766445`（Okinawa，11/41）等；`Q82112` 類的裸 QID 缺 label 情況也出現在 `Q1037393`（Hokkaidō，但本身第二層 14 個振興局資料正確完整，只是自身 label 缺失）。
- **Japan**：`Q1037393`（Hokkaidō）第二層並非市町村而是 14 個「振興局」（subprefecture）——這是北海道特有的真實行政結構（市町村是第三層），14/14 全數正確接受，非資料缺口。
- **Taiwan**：全部 6 個第一層節點（Taoyuan `Q115256`、Tainan `Q140631`、New Taipei `Q244898`、Taichung `Q245023`、Taipei `Q1867`、Kaohsiung `Q181557`）本身都缺 label observation（顯示成裸 QID，同 France `Q15104`/Mexico `Q82112`/Germany `Q1194`/UK `Q21` 那類第一層舊缺口），需 refresh_observations 補齊；第二層資料本身無缺口，皆精確吻合官方行政區數。
- **South Korea**：無已知資料缺口，17 個一級行政區的第二層皆精確吻合官方市郡區數，第一層節點也無裸 QID 情況。
- **Malaysia**：9 個州 P150 完全 0 候選（實際各州皆有 3~12 個縣不等）：`Q213893`（Negeri Sembilan）、`Q183032`（Johor）、`Q185221`（Malacca）、`Q185944`（Kelantan，本身還缺 label observation）、`Q188096`（Penang）、`Q188947`（Kedah）、`Q189701`（Terengganu）、`Q189710`（Selangor）、`Q191346`（Pahang）。只有 Sarawak/Sabah/Perak 三州有實際資料，是本輪目前候選覆蓋率最差的國家。
- **Canada**：7 個省/地區 P150 完全 0 候選：`Q1904`（Ontario，人口最多的省）、`Q1951`（Alberta）、`Q1948`（Manitoba）、`Q1989`（Saskatchewan）、`Q2003`（Newfoundland and Labrador）、`Q2009`（Yukon）、`Q2023`（Nunavut）。只有 Nova Scotia/New Brunswick/British Columbia/PEI/Northwest Territories/Quebec 六個有實際資料。
- **Portugal**：13 個區 P150 完全 0 候選：`Q210527`（Aveiro）、`Q225189`（Portalegre）、`Q244510`（Santarém）、`Q244512`（Leiria）、`Q244517`（Coimbra）、`Q273529`（Castelo Branco）、`Q321455`（Beja）、`Q379372`（Vila Real）、`Q25263`（Azores 自治區）、`Q244521`（Faro）、`Q273525`（Viseu）、`Q273533`（Guarda）、`Q326214`（Viana do Castelo）。只有 Lisbon/Setúbal/Porto/Braga/Madeira/Bragança 六個區有實際資料；`Q274118`（未知區名，僅 1 個候選）本身還缺 label observation，同 France `Q15104` 那類第一層舊缺口。
- **Saudi Arabia**：13 個省中 11 個 P150 完全 0 候選：`Q234167`（Mecca）、`Q236027`（Medina）、`Q243656`（Ha'il）、`Q269973`（Jazan）、`Q464718`（Najran）、`Q779855`（Asir）、`Q852774`（Al-Baha）、`Q953508`（Eastern Province）、`Q1105411`（Al-Qassim）、`Q1315953`（Tabuk）、`Q1471266`（Al-Jowf）。只有 Northern Borders Province/Riyadh Province 兩省有實際資料（各僅 1-2 筆），是本輪候選覆蓋率最差的國家之一。
- **UAE**：7 個酋長國 P150 全數 0 候選（`Q159477` Ajman、`Q170024` Ras Al Khaimah、`Q175021` Umm Al Quwain、`Q187712` Abu Dhabi、`Q188810` Sharjah、`Q613` Dubai、`Q4091` Fujairah），是本輪唯一「整個國家零第二層資料」的案例——包含 Dubai/Abu Dhabi 這種資料量通常很豐富的地方也是 0，推測阿聯的市級行政區（如 Dubai 的 municipality）在 Wikidata 沒有用 P150 連到酋長國本身。
- **Vietnam/Morocco**：無已知資料缺口，皆完整涵蓋全部一級行政區。
- **Egypt**：27 省中 26 省 P150 完全 0 候選，只有 `Q30835`（Aswan Governorate，7 筆）有實際資料，是本輪目前候選覆蓋率最差的國家。其餘 26 省 QID：`Q29937`、`Q29943`、`Q29965`、`Q30630`、`Q30644`、`Q30650`、`Q30656`、`Q30662`、`Q30669`（本身還缺 label observation）、`Q30675`、`Q30682`、`Q30683`、`Q30786`、`Q30797`、`Q30805`、`Q30815`、`Q30831`、`Q30832`、`Q30946`、`Q31067`、`Q31065`、`Q31070`、`Q31068`、`Q31074`、`Q31075`、`Q31079`。
- **Tunisia**：24 省中 22 省 P150 完全 0 候選，只有 `Q233116`（Ben Arous Governorate，7 筆）、`Q241129`（Sfax Governorate，14 筆）有實際資料。其餘 22 省 QID：`Q238555`、`Q241145`、`Q242263`、`Q269968`、`Q276565`、`Q276574`、`Q276576`、`Q276580`、`Q286063`、`Q318102`、`Q327045`、`Q327087`、`Q327097`、`Q27916`、`Q328109`、`Q328115`、`Q328145`、`Q328164`、`Q328199`、`Q388047`、`Q388059`、`Q734328`。
- **Dominican Republic**：32 省全數 P150 完全 0 候選（`Q530231`、`Q549386`、`Q592624`、`Q594405`、`Q693487`、`Q794239`、`Q807079`、`Q937217`、`Q1137545`、`Q1137551`、`Q1138575`、`Q1140742`、`Q1145487`、`Q1262745`、`Q1295496`、`Q1323353`、`Q1331932`、`Q1352533`、`Q1352536`、`Q1366107`、`Q1366119`、`Q1424391`、`Q1424401`、`Q1772745`、`Q1772983`、`Q1774831`、`Q1774848`、`Q1836903`、`Q1949656`、`Q2001793`、`Q2021942`、`Q2499228`），包含首都聖多明哥所在的 Distrito Nacional/Santo Domingo Province 也是 0，繼 UAE 之後本輪第二個「整個國家零第二層資料」案例。
- **Brazil**：`Q40430`（Bahia，417 候選）連續 3 次重跑都因 agy 輸出被截斷導致 JSON 解析失敗（每次截斷位置不同），**不是 P150 資料缺口**（候選確實存在），而是腳本尚未處理「單次候選清單過大」的技術限制；`Q119158`（Federal District）P150 0 候選但屬正常結構（聯邦區本身即為最終層級，無次級市鎮，同 Washington D.C. 模式），非缺口不需處理。
